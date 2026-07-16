<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Contracts;

use Webyashopy\Chatbot\Support\ChatActionResult;

/**
 * Kontrakt pro potvrzení navrženého zápisu (proposal → skutečný zápis).
 *
 * Nahrazuje doménový `match ($action['kind'])` v controlleru: balíček jen
 * najde handler odpovídajícího `kind()` a předá mu payload návrhu.
 *
 * Zápis ZŮSTÁVÁ V HOSTOVI (ADR-017 §4) — balíček nikdy nevolá
 * `Model::create` nad doménou. Implementace v hostovi musí:
 *  - validovat payload vlastním FormRequestem (payload pochází od modelu,
 *    tedy z neověřeného zdroje),
 *  - zapsat audit s `origin=chatbot`,
 *  - re-autorizovat uživatele.
 *
 * Handlery dodává HOST — balíček je registruje discovery nad
 * `chatbot.actions.discover_paths`, případně explicitně přes
 * {@see \Webyashopy\Chatbot\Chatbot::registerActionHandler()}.
 *
 * Parametr `$user` je `mixed` — balíček host User model neimportuje.
 */
interface ChatActionHandler
{
    /**
     * Druh akce, kterou handler obsluhuje (např. 'customer_order').
     *
     * Musí odpovídat `kind` z návrhu vráceného write nástrojem.
     */
    public function kind(): string;

    /**
     * Potvrdí návrh a provede zápis v doméně host aplikace.
     *
     * @param  array<string, mixed>  $payload  Návrh z proposal odpovědi modelu.
     * @param  mixed  $user  Autentizovaný uživatel host aplikace.
     */
    public function confirm(array $payload, mixed $user): ChatActionResult;
}
