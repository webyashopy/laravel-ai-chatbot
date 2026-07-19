<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Webyashopy\Chatbot\Contracts\ChatAuthorizer;
use Webyashopy\Chatbot\Models\UserAiSettings;

/**
 * Self-service nastavení AI per uživatel — vlastní Anthropic API klíč
 * (`user_ai_settings`, ADR-015). Každý uživatel spravuje VÝHRADNĚ svůj
 * záznam (scope přes `request->user()`), žádný parametr s cizím user_id
 * neexistuje.
 *
 * Klíč se do frontendu NIKDY neposílá — props nesou jen `has_api_key: bool`
 * (kontrakt modelu {@see UserAiSettings}: `encrypted` cast + `$hidden`).
 * Inertia stránku `chat/settings` dodává host (stejný vzor jako `chat/index`).
 *
 * `$user` je `mixed` — balíček host User model neimportuje (ADR-019).
 */
class ChatSettingsController extends Controller
{
    public function __construct(
        private readonly ChatAuthorizer $authorizer,
    ) {}

    /**
     * Stav nastavení přihlášeného uživatele. `require_user_key` jde do props,
     * aby FE uměl vysvětlit, že bez vlastního klíče chat nepojede.
     */
    public function show(Request $request): Response
    {
        $this->ensureCanUseChat($request);

        $settings = $this->settingsFor($request->user());

        return Inertia::render('chat/settings', [
            'has_api_key' => $settings?->api_key !== null && $settings->api_key !== '',
            'preferred_model' => $settings?->preferred_model,
            'require_user_key' => (bool) config('chatbot.api.require_user_key', false),
        ]);
    }

    /**
     * Uloží (nebo přepíše) vlastní API klíč přihlášeného uživatele.
     *
     * Formát klíče se validuje jen volně (min. délka) — balíček nesmí
     * vynucovat prefix `sk-ant-`, host může mířit na kompatibilní gateway
     * (`chatbot.api.url` je konfigurovatelná).
     */
    public function update(Request $request): RedirectResponse
    {
        $this->ensureCanUseChat($request);

        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:20', 'max:512'],
        ]);

        UserAiSettings::query()->updateOrCreate(
            ['user_id' => $request->user()->getKey()],
            ['api_key' => $data['api_key']],
        );

        return to_route('chat.settings.show')
            ->with('toast', ['type' => 'success', 'message' => 'API klíč byl uložen.']);
    }

    /**
     * Odstraní vlastní API klíč (záznam zůstává kvůli `preferred_model`).
     * Bez striktního režimu se tím uživatel vrací na serverový klíč z env.
     */
    public function destroyKey(Request $request): RedirectResponse
    {
        $this->ensureCanUseChat($request);

        $this->settingsFor($request->user())?->update(['api_key' => null]);

        return to_route('chat.settings.show')
            ->with('toast', ['type' => 'success', 'message' => 'API klíč byl odstraněn.']);
    }

    /**
     * Záznam nastavení přihlášeného uživatele — dotazem přes `user_id`, ne
     * relací na host User modelu (balíček žádnou relaci nepředpokládá).
     */
    private function settingsFor(mixed $user): ?UserAiSettings
    {
        return UserAiSettings::query()
            ->where('user_id', $user?->getKey())
            ->first();
    }

    /**
     * Stejná brána jako v {@see ChatController} — defense-in-depth nad
     * middleware z `chatbot.routes.middleware` (ADR-017 §5).
     */
    private function ensureCanUseChat(Request $request): void
    {
        abort_unless($this->authorizer->canUseChat($request->user()), 403, 'Nemáte přístup k chatu.');
    }
}
