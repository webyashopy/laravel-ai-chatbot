<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;
use Webyashopy\Chatbot\Contracts\ChatAuthorizer;
use Webyashopy\Chatbot\Enums\ChatRole;
use Webyashopy\Chatbot\Models\AiUsageLog;
use Webyashopy\Chatbot\Models\ChatConversation;
use Webyashopy\Chatbot\Models\ChatMessage;
use Webyashopy\Chatbot\Services\AiService;
use Webyashopy\Chatbot\Services\AssistantService;
use Webyashopy\Chatbot\Services\ChatActionHandlerRegistry;
use Webyashopy\Chatbot\Support\Purpose;
use Webyashopy\Chatbot\Support\SystemPrompt;

/**
 * Chat (ADR-016, tool-use ADR-017) — konverzace jsou privátní vlastníkovi
 * ({@see \Webyashopy\Chatbot\Policies\ChatConversationPolicy}). Je-li zapnutý
 * tool-use (`chatbot.chat.tools.enabled`) a zvolený model je tool-capable
 * (`chatbot.chat.tools.capable_models`), zprávy jdou přes agentní smyčku
 * {@see AssistantService::run()}. Jinak (nebo když tool-loop selže) se použije
 * čistě textový fallback {@see AiService::complete()}.
 *
 * ŽÁDNÁ DOMÉNA: controller neví nic o tom, co se potvrzením zapisuje. Dřívější
 * `match ($action['kind'])` nad doménovými typy nahradil
 * {@see ChatActionHandlerRegistry} — zápis provádí HOST ve svém
 * {@see \Webyashopy\Chatbot\Contracts\ChatActionHandler} (ADR-017 §4:
 * balíček nikdy nezapisuje doménu). Neznámý `kind` → 422, nikdy zápis.
 *
 * Systémové prompty jdou přes {@see SystemPrompt} — bezpečnostní preambule je
 * fixní v balíčku, host přidává jen `chatbot.prompts.context` (ADR-019 §7).
 *
 * `$user` je všude `mixed` — balíček host User model neimportuje (ADR-019).
 *
 * MVP je synchronní (streaming je samostatný task).
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly AiService $aiService,
        private readonly AssistantService $assistantService,
        private readonly ChatActionHandlerRegistry $actionHandlers,
        private readonly ChatAuthorizer $authorizer,
        private readonly SystemPrompt $systemPrompt,
    ) {}

    /**
     * Seznam konverzací přihlášeného uživatele (bez aktivní konverzace).
     */
    public function index(Request $request): Response
    {
        $this->ensureCanUseChat($request);

        return Inertia::render('chat/index', [
            'conversations' => $this->conversationList($request->user()),
            'active' => null,
            'models' => config('chatbot.models'),
            'default_model' => config('chatbot.default_model'),
        ]);
    }

    /**
     * Založení nové konverzace + první výměna (uživatel → asistent).
     */
    public function store(Request $request): RedirectResponse
    {
        $this->ensureCanUseChat($request);

        $data = $request->validate([
            'model' => ['required', 'string'],
            'message' => ['required', 'string'],
        ]);

        $this->ensureModelAllowed($data['model']);

        $conversation = ChatConversation::create([
            'user_id' => $request->user()->getKey(),
            'title' => Str::limit($data['message'], 60),
            'model' => $data['model'],
        ]);

        $this->exchange($conversation, $request->user(), $data['message'], $data['model']);

        return to_route('chat.show', $conversation);
    }

    /**
     * Detail konverzace s historií zpráv. 403, pokud uživatel není vlastník.
     */
    public function show(Request $request, ChatConversation $conversation): Response
    {
        $this->ensureCanUseChat($request);
        Gate::authorize('view', $conversation);

        return Inertia::render('chat/index', [
            'conversations' => $this->conversationList($request->user()),
            'active' => $this->conversationDetail($conversation),
            'models' => config('chatbot.models'),
            'default_model' => config('chatbot.default_model'),
        ]);
    }

    /**
     * Odeslání zprávy do existující konverzace, volitelně s přepnutím modelu.
     */
    public function message(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $this->ensureCanUseChat($request);
        Gate::authorize('update', $conversation);

        $data = $request->validate([
            'message' => ['required', 'string'],
            'model' => ['nullable', 'string'],
        ]);

        $model = $data['model'] ?? $conversation->model;

        if (! empty($data['model'])) {
            $this->ensureModelAllowed($model);
        }

        if ($model !== $conversation->model) {
            $conversation->update(['model' => $model]);
        }

        $this->exchange($conversation, $request->user(), $data['message'], $model);

        return to_route('chat.show', $conversation);
    }

    /**
     * Smazání konverzace (vlastník). 403 jinak.
     */
    public function destroy(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $this->ensureCanUseChat($request);
        Gate::authorize('delete', $conversation);

        $conversation->delete();

        return to_route('chat.index')
            ->with('toast', ['type' => 'success', 'message' => 'Konverzace byla smazána.']);
    }

    /**
     * Potvrzení nebo zrušení návrhu zápisu z write nástroje (ADR-017 §4).
     *
     * `confirm` deleguje zápis na {@see \Webyashopy\Chatbot\Contracts\ChatActionHandler}
     * hosta podle `action.kind` — balíček sám nikdy nic doménového nezapíše a
     * pro neznámý `kind` skončí 422. `cancel` nezapíše nic. Idempotentní —
     * již vyřízený návrh (confirmed/cancelled) vrací 409.
     */
    public function confirmAction(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $this->ensureCanUseChat($request);
        Gate::authorize('update', $conversation);

        $data = $request->validate([
            'message_id' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['confirm', 'cancel'])],
        ]);

        // Zpráva se hledá VÝHRADNĚ ve scope této konverzace (jejíž vlastnictví
        // je ověřené výše) — jinak by cizí message_id šlo potvrdit přes vlastní
        // konverzaci (IDOR).
        $message = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereKey($data['message_id'])
            ->firstOrFail();

        $action = $message->action;

        abort_if($action === null, 404, 'Zpráva nemá žádný navržený zápis.');
        // Idempotence — už vyřízený návrh (confirmed/cancelled) nelze potvrdit/zrušit znovu.
        abort_if(($action['status'] ?? null) !== 'pending', 409, 'Návrh už byl vyřízen.');

        if ($data['decision'] === 'cancel') {
            $message->update(['action' => [...$action, 'status' => 'cancelled']]);

            return to_route('chat.show', $conversation)
                ->with('toast', ['type' => 'info', 'message' => 'Návrh byl zrušen.']);
        }

        return $this->confirmProposal($conversation, $message, $action, $request->user());
    }

    /**
     * Provede potvrzený návrh přes handler hosta a promítne výsledek do
     * `action.status`.
     *
     * Handler smí neúspěch ohlásit dvěma způsoby a OBA končí 302 +
     * `session('errors')` (vědomá odchylka od ADR-017, kontrakt
     * `chatbot-tools.md`, NESMÍ se posunout na 422 JSON):
     *
     *  - vrátí {@see ChatActionResult::failure()} s `errors` → přesměrování zde,
     *  - vyhodí `ValidationException` (typicky z hostova FormRequestu) →
     *    přesměrování udělá exception handler Laravelu. Výjimku proto
     *    ZÁMĚRNĚ neodchytáváme.
     *
     * Návrh zůstává v obou případech `pending` — uživatel může opravit a potvrdit znovu.
     *
     * @param  array<string, mixed>  $action
     */
    private function confirmProposal(
        ChatConversation $conversation,
        ChatMessage $message,
        array $action,
        mixed $user,
    ): RedirectResponse {
        $kind = $action['kind'] ?? null;
        $handler = is_string($kind) ? $this->actionHandlers->get($kind) : null;

        // Neznámý druh návrhu se NIKDY nezapisuje — host pro něj handler nedodal.
        abort_if($handler === null, 422, 'Neznámý typ navrženého zápisu.');

        // Druhá autorizační brána nad rámec `canUseChat()` — host může chtít
        // potvrzování zápisu povolit jinému okruhu lidí než čtení chatu.
        abort_unless($this->authorizer->canConfirmAction($user, $kind), 403, 'Nemáte oprávnění potvrdit tento zápis.');

        $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];

        // Původ návrhu — host si ho zapíše do auditu, aby šlo dohledat, ZE KTERÉ konverzace
        // zápis vzešel (ADR-004). Bez toho by audit věděl jen „založil chatbot“, ne odkud.
        $result = $handler->confirm($payload, $user, [
            'conversation_id' => $conversation->id,
            'chat_message_id' => $message->id,
        ]);

        if (! $result->ok) {
            return back()
                ->withErrors($result->errors ?? [])
                ->with('toast', ['type' => 'error', 'message' => $result->message ?? 'Zápis se nepodařilo provést.']);
        }

        $message->update(['action' => [
            ...$action,
            'status' => 'confirmed',
            // `result_id` je smluvní (chatbot-tools.md) — FE na něj váže odkaz na záznam.
            'result_id' => $result->resultId,
        ]]);

        return to_route(
            $result->redirectRoute ?? 'chat.show',
            $result->redirectParams ?? ['conversation' => $conversation->id],
        )->with('toast', ['type' => 'success', 'message' => $result->message ?? 'Zápis byl proveden.']);
    }

    /**
     * Brána chatu (ADR-019) — `ChatAuthorizer` hosta, ne Spatie oprávnění.
     *
     * Kontrola je tu i přesto, že si host typicky dá gate do
     * `chatbot.routes.middleware` (JNS: `can:chat.use`): výchozí middleware
     * balíčku je jen `['web','auth']`, takže bez téhle brány by byl balíček
     * po instalaci otevřený každému přihlášenému. Defense-in-depth (ADR-017 §5).
     */
    private function ensureCanUseChat(Request $request): void
    {
        abort_unless($this->authorizer->canUseChat($request->user()), 403, 'Nemáte přístup k chatu.');
    }

    /**
     * Ověří model proti allowlistu `config('chatbot.models')` — mimo allowlist → 422
     * (žádný libovolný passthrough na Anthropic API, viz ADR-016).
     */
    private function ensureModelAllowed(string $model): void
    {
        if (! in_array($model, (array) config('chatbot.models', []), true)) {
            abort(422, 'Nepovolený model — mimo allowlist.');
        }
    }

    /**
     * Uloží uživatelskou zprávu a podle konfigurace/modelu ji předá buď
     * tool-use smyčce ({@see exchangeWithTools()}), nebo čistě textovému
     * fallbacku ({@see exchangeAsText()}). Chyba se nešíří jako 500 —
     * uživatelská zpráva zůstane uložena, jen bez odpovědi asistenta
     * (konverzace zůstává použitelná dál).
     */
    private function exchange(ChatConversation $conversation, mixed $user, string $message, string $model): void
    {
        $historyLimit = (int) config('chatbot.chat.history_limit', 20);

        $history = $conversation->messages()
            ->orderByDesc('id')
            ->limit($historyLimit)
            ->get()
            ->reverse();

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => ChatRole::User,
            'content' => $message,
        ]);

        if ($this->shouldUseTools($model)) {
            $this->exchangeWithTools($conversation, $user, $history, $message, $model);

            return;
        }

        $this->exchangeAsText($conversation, $user, $history, $message, $model);
    }

    /**
     * Tool-use větev (ADR-017) — volá {@see AssistantService::run()} nad polem
     * zpráv (historie + nová zpráva), uloží finální text, provedené kroky
     * (`steps`) a případný navržený zápis (`action`, stav `pending`) na
     * asistentskou zprávu. Chyba tool-loopu (Anthropic API, tool handler)
     * konverzaci NESHODÍ — stejné graceful chování jako textová větev.
     *
     * @param  Collection<int, ChatMessage>  $history
     */
    private function exchangeWithTools(
        ChatConversation $conversation,
        mixed $user,
        Collection $history,
        string $message,
        string $model,
    ): void {
        $messages = $this->buildMessages($history, $message);

        try {
            $result = $this->assistantService->run($messages, $this->systemPrompt->withTools(), $user, [
                'purpose' => Purpose::CHAT,
                'model' => $model,
                'conversation_id' => $conversation->id,
            ]);
        } catch (Throwable $e) {
            // AssistantService/AiService už chybu zalogovaly do ai_usage_logs (success=false)
            // — uživateli se jen neuloží odpověď asistenta, konverzace zůstává použitelná dál.
            Log::warning('ChatController: chyba tool-use smyčky, konverzace pokračuje bez odpovědi', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $text = $result['text'] !== '' ? $result['text'] : 'Omlouvám se, nepodařilo se sestavit odpověď.';
        $steps = $result['steps'];

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => ChatRole::Assistant,
            'content' => $text,
            'model' => $model,
            'ai_usage_log_id' => $this->latestUsageLog($conversation)?->id,
            'steps' => $steps !== [] ? $steps : null,
            'action' => $result['action'],
        ]);
    }

    /**
     * Čistě textová větev (fallback) — použije se, když jsou nástroje vypnuté
     * nebo zvolený model není tool-capable.
     *
     * @param  Collection<int, ChatMessage>  $history
     */
    private function exchangeAsText(
        ChatConversation $conversation,
        mixed $user,
        Collection $history,
        string $message,
        string $model,
    ): void {
        $prompt = $this->buildPrompt($history, $message);

        try {
            $result = $this->aiService->complete($prompt, $this->systemPrompt->textOnly(), [
                'user' => $user,
                'purpose' => Purpose::CHAT,
                'model' => $model,
                'conversation_id' => $conversation->id,
            ]);
        } catch (Throwable) {
            // AiService už chybu zalogovala do ai_usage_logs (success=false) — uživateli
            // se jen neuloží odpověď asistenta, konverzace zůstává použitelná dál.
            return;
        }

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => ChatRole::Assistant,
            'content' => $result['content'],
            'model' => $result['model'],
            'ai_usage_log_id' => $this->latestUsageLog($conversation)?->id,
        ]);
    }

    /**
     * Nástroje se použijí jen když je globální kill-switch zapnutý A zvolený
     * model je na allowlistu tool-capable modelů (ADR-017 §6) — jinak fallback
     * na text, žádný pád.
     */
    private function shouldUseTools(string $model): bool
    {
        if (! (bool) config('chatbot.chat.tools.enabled', true)) {
            return false;
        }

        return in_array($model, (array) config('chatbot.chat.tools.capable_models', []), true);
    }

    /**
     * Poslední zalogovaný `ai_usage_logs` řádek pro tuto konverzaci —
     * navázán na asistentskou zprávu po jejím uložení (stejně v obou větvích).
     */
    private function latestUsageLog(ChatConversation $conversation): ?AiUsageLog
    {
        return AiUsageLog::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->first();
    }

    /**
     * Sestaví prompt z ořezané historie + nové zprávy (AiService::complete()
     * bere jeden textový prompt, ne pole zpráv).
     *
     * @param  Collection<int, ChatMessage>  $history
     */
    private function buildPrompt(Collection $history, string $newMessage): string
    {
        $lines = [];

        foreach ($history as $item) {
            $lines[] = $item->role->label().": {$item->content}";
        }

        $lines[] = ChatRole::User->label().": {$newMessage}";

        return implode("\n", $lines);
    }

    /**
     * Sestaví pole zpráv pro {@see AssistantService::run()} (multi-turn) z
     * ořezané historie + nové zprávy uživatele. Předchozí tool kroky/návrhy
     * se do kontextu záměrně NEpřidávají (úspora tokenů) — jen textový obsah
     * zpráv, stejně jako u `buildPrompt()`.
     *
     * @param  Collection<int, ChatMessage>  $history
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(Collection $history, string $newMessage): array
    {
        $messages = [];

        foreach ($history as $item) {
            $messages[] = [
                'role' => $item->role->value,
                'content' => $item->content,
            ];
        }

        $messages[] = ['role' => ChatRole::User->value, 'content' => $newMessage];

        return $messages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conversationList(mixed $user): array
    {
        return ChatConversation::query()
            ->where('user_id', $user?->getKey())
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (ChatConversation $c): array => [
                'id' => $c->id,
                'title' => $c->title,
                'model' => $c->model,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationDetail(ChatConversation $conversation): array
    {
        $conversation->load('messages');

        return [
            'id' => $conversation->id,
            'model' => $conversation->model,
            'messages' => $conversation->messages
                ->map(fn (ChatMessage $m): array => [
                    'id' => $m->id,
                    'role' => $m->role->value,
                    'content' => $m->content,
                    'model' => $m->model,
                    'created_at' => $m->created_at?->toIso8601String(),
                    'action' => $m->action,
                    'steps' => $this->stepsForProps($m->steps),
                ])
                ->all(),
        ];
    }

    /**
     * Zúží interní tvar kroků (`tool`, `input`, `summary` — {@see AssistantService::run()})
     * na read-only tvar pro FE (`tool`, `summary` — kontrakt `chatbot-tools.md`). `input`
     * (surové filtry poslané modelem nástroji) se do props záměrně nepropisuje.
     *
     * @param  array<int, array<string, mixed>>|null  $steps
     * @return array<int, array{tool: string, summary: string}>|null
     */
    private function stepsForProps(?array $steps): ?array
    {
        if ($steps === null || $steps === []) {
            return null;
        }

        return array_map(
            static fn (array $step): array => [
                'tool' => (string) ($step['tool'] ?? ''),
                'summary' => (string) ($step['summary'] ?? ''),
            ],
            $steps,
        );
    }
}
