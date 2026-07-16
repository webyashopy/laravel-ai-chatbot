<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Contracts;

/**
 * Kontrakt nástroje (tool-use), který model volá nad daty host aplikace.
 *
 * Nástroje dodává HOST — balíček doménu nezná, jen je registruje
 * (discovery nad `chatbot.tools.discover_paths`, případně explicitně
 * přes {@see \Webyashopy\Chatbot\Chatbot::registerTool()}).
 *
 * Invarianty (ADR-017), které implementace nesmí porušit:
 *  - handler se VŽDY re-autorizuje pod přihlášeným uživatelem, nikdy
 *    neběží pod servisní identitou,
 *  - žádný zápis do domény z nástroje — write nástroje vrací pouze návrh
 *    `['status' => 'proposal', 'kind' => …, 'payload' => …, 'summary' => …]`
 *    a tím smyčku ukončí (human-in-the-loop),
 *  - žádný raw SQL / passthrough filtrů — jen typované filtry přes Eloquent
 *    s limitem řádků (max 50).
 *
 * Parametr `$user` je `mixed` — balíček host User model neimportuje.
 */
interface ChatTool
{
    /**
     * Jméno nástroje, kterým ho model volá (např. 'read_faktury').
     */
    public function name(): string;

    /**
     * Definice nástroje ve schématu Anthropic tool-use
     * (`name`, `description`, `input_schema`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * Provede nástroj a vrátí payload pro `tool_result`.
     *
     * @param  array<string, mixed>  $input  Vstup od modelu (dle `input_schema`).
     * @param  mixed  $user  Autentizovaný uživatel host aplikace.
     * @return array<string, mixed>
     */
    public function handle(array $input, mixed $user): array;
}
