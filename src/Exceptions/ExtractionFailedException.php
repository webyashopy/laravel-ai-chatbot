<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Exceptions;

use RuntimeException;

/**
 * Model odpověděl, ale odpověď nejde použít jako strukturovaná data.
 *
 * Odlišené od chyby API (ta letí jako `\RuntimeException` z
 * {@see \Webyashopy\Chatbot\Services\AiService}) — tady volání proběhlo,
 * platí se za něj a v `document_extractions` po něm zůstane řádek se
 * `status = 'failed'`.
 */
final class ExtractionFailedException extends RuntimeException
{
    public static function invalidJson(string $schema, string $error): self
    {
        return new self("Extrakce schématem [{$schema}] nevrátila platný JSON: {$error}");
    }

    public static function notAnObject(string $schema): self
    {
        return new self("Extrakce schématem [{$schema}] nevrátila JSON objekt.");
    }

    /**
     * `stop_reason = max_tokens` — JSON je useknutý uprostřed. Řešení je
     * zvýšit `max_tokens`, ne opakovat volání se stejnými parametry.
     */
    public static function truncated(string $schema): self
    {
        return new self(
            "Extrakce schématem [{$schema}] byla useknutá limitem max_tokens — ".
            'zvyš `max_tokens` nebo zjednoduš schéma.'
        );
    }

    /**
     * `stop_reason = refusal` — bezpečnostní klasifikátor Anthropic odmítl
     * obsah dokumentu. Opakování se stejným vstupem nepomůže.
     */
    public static function refused(string $schema): self
    {
        return new self("Model odmítl zpracovat dokument pro schéma [{$schema}].");
    }
}
