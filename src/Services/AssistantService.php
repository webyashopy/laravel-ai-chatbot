<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Services;

use Illuminate\Support\Facades\Log;
use Throwable;
use Webyashopy\Chatbot\Contracts\ChatTool;
use Webyashopy\Chatbot\Support\Purpose;

/**
 * Orchestrace agentní smyčky tool-use nad {@see AiService::converse()} (ADR-017).
 *
 * Dokud model vrací `stop_reason === 'tool_use'`, provede pro každý `tool_use`
 * blok odpovídající {@see ChatTool} z {@see ChatToolRegistry} POD PRÁVY
 * přihlášeného uživatele (nikdy servisní identita), přidá `tool_result` do
 * zpráv a volá `converse()` znovu. Tvrdý limit iterací
 * (`config('chatbot.chat.tools.max_iterations')`, default 5) chrání proti
 * zacyklení — po jeho vyčerpání smyčka vrátí poslední textovou odpověď (bez pádu).
 *
 * INVARIANTY (ADR-017), které tato třída drží STRUKTURÁLNĚ, ne dokumentací:
 *
 *  - Smyčka nemá žádnou cestu k zápisu domény. Jediné, co s nástrojem dělá, je
 *    `handle()` a serializace jeho návratu do `tool_result` — o zápisu rozhoduje
 *    až host v {@see \Webyashopy\Chatbot\Contracts\ChatActionHandler} po
 *    explicitním potvrzení uživatelem.
 *  - Write (proposal) nástroj smyčku ukončí OKAMŽITĚ (`break`), takže model
 *    nemůže na návrh navázat dalším round-tripem ani si ho „odklikat" sám
 *    (human-in-the-loop).
 *
 * `$user` je `mixed` — balíček host User model neimportuje (ADR-019); jen ho
 * propaguje do nástrojů a do usage logu.
 */
class AssistantService
{
    public function __construct(
        private readonly AiService $aiService,
        private readonly ChatToolRegistry $registry,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages  Historie konverzace, poslední prvek =
     *                                                      nová zpráva uživatele (role user).
     * @param  string|array<int, array<string, mixed>>|null  $system
     * @param  array<string, mixed>  $options  Kontext pro AiService::converse() — `purpose`
     *                                         (default chat), `model`, `conversation_id`.
     * @return array{
     *     text: string,
     *     steps: array<int, array{tool: string, input: array<string,mixed>, summary: string}>,
     *     stop_reason: string|null,
     *     action: array{kind: mixed, payload: array<string,mixed>, summary: string, status: string}|null,
     * }
     */
    public function run(array $messages, string|array|null $system, mixed $user, array $options = []): array
    {
        $tools = $this->registry->definitions();
        // Strop zdola i SHORA: bez horní meze si host přes CHATBOT_TOOLS_MAX_ITERATIONS=1000
        // udělá libovolně drahou smyčku. 10 je nad rámec všech reálných scénářů
        // (default 5) a drží náklad ohraničený. (Nález security auditu TASK-099.)
        $maxIterations = min(10, max(1, (int) config('chatbot.chat.tools.max_iterations', 5)));

        $steps = [];
        $text = '';
        $stopReason = null;
        $action = null;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $result = $this->aiService->converse($messages, $system, [
                ...$options,
                'user' => $user,
                'purpose' => $options['purpose'] ?? Purpose::CHAT,
                'tools' => $tools,
            ]);

            $stopReason = $result['stop_reason'] ?? null;
            $content = $result['content'] ?? [];
            $extractedText = $this->extractText($content);
            $text = $extractedText !== '' ? $extractedText : $text;

            $messages[] = ['role' => 'assistant', 'content' => $content];

            if ($stopReason !== 'tool_use') {
                break;
            }

            $toolUseBlocks = array_values(array_filter(
                $content,
                static fn (array $block): bool => ($block['type'] ?? null) === 'tool_use',
            ));

            // Model ohlásil tool_use, ale žádný blok nenašel (nekonzistentní odpověď) — konec smyčky.
            if ($toolUseBlocks === []) {
                break;
            }

            $toolResultBlocks = [];

            foreach ($toolUseBlocks as $block) {
                [$step, $resultPayload, $proposedAction] = $this->executeTool($block, $user);

                $steps[] = $step;
                $toolResultBlocks[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'] ?? null,
                    'content' => json_encode($resultPayload, JSON_UNESCAPED_UNICODE) ?: '{}',
                ];

                if ($proposedAction !== null) {
                    $action = $proposedAction;
                }
            }

            $messages[] = ['role' => 'user', 'content' => $toolResultBlocks];

            // Write nástroj vrátil návrh (proposal) — smyčka záměrně KONČÍ, čeká se na
            // explicitní potvrzení uživatele (ADR-017 §4), žádný další round-trip.
            if ($action !== null) {
                break;
            }
        }

        // Asistentská zpráva s návrhem má textový obsah = souhrn návrhu (kontrakt
        // chatbot-tools.md), pokud model sám žádný doprovodný text nevrátil.
        if ($text === '' && $action !== null) {
            $text = (string) ($action['summary'] ?? '');
        }

        return [
            'text' => $text,
            'steps' => $steps,
            'stop_reason' => $stopReason,
            'action' => $action,
        ];
    }

    /**
     * Provede jeden `tool_use` blok — najde handler v registru, zavolá ho pod
     * identitou přihlášeného uživatele a namapuje výsledek na krok průběhu +
     * tool_result payload. Neznámý nástroj i výjimka v handleru → chybový
     * tool_result (NE pád smyčky).
     *
     * @param  array<string, mixed>  $block
     * @return array{
     *     0: array{tool: string, input: array<string,mixed>, summary: string},
     *     1: array<string, mixed>,
     *     2: array{kind: mixed, payload: array<string,mixed>, summary: string, status: string}|null,
     * }
     */
    private function executeTool(array $block, mixed $user): array
    {
        $name = (string) ($block['name'] ?? '');
        $input = is_array($block['input'] ?? null) ? $block['input'] : [];

        $tool = $this->registry->get($name);

        if ($tool === null) {
            return [
                ['tool' => $name, 'input' => $input, 'summary' => 'Neznámý nástroj'],
                ['error' => "Neznámý nástroj „{$name}“."],
                null,
            ];
        }

        try {
            $result = $tool->handle($input, $user);
        } catch (Throwable $e) {
            Log::warning('AssistantService: chyba při provádění nástroje', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            return [
                ['tool' => $name, 'input' => $input, 'summary' => 'Chyba při provádění nástroje'],
                ['error' => 'Nástroj selhal: '.$e->getMessage()],
                null,
            ];
        }

        $proposedAction = ($result['status'] ?? null) === 'proposal'
            ? [
                'kind' => $result['kind'] ?? null,
                'payload' => is_array($result['payload'] ?? null) ? $result['payload'] : [],
                'summary' => (string) ($result['summary'] ?? ''),
                'status' => 'pending',
            ]
            : null;

        return [
            ['tool' => $name, 'input' => $input, 'summary' => (string) ($result['summary'] ?? $name)],
            $result,
            $proposedAction,
        ];
    }

    /**
     * Spojí textové bloky odpovědi do jednoho stringu (tool_use bloky ignoruje).
     *
     * @param  array<int, array<string, mixed>>  $content
     */
    private function extractText(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }

        return trim(implode("\n", $parts));
    }
}
