<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Exceptions;

use RuntimeException;

/**
 * Požadované schéma extrakce neexistuje v registru
 * ({@see \Webyashopy\Chatbot\Services\DocumentSchemaRegistry}).
 *
 * Typicky: překlep v názvu, třída mimo `chatbot.documents.schemas.discover_paths`,
 * nebo vypnutá feature `chatbot.features.documents` (registr je pak prázdný).
 */
final class UnknownDocumentSchemaException extends RuntimeException
{
    /**
     * @param  array<int, string>  $known
     */
    public static function named(string $name, array $known): self
    {
        return new self(sprintf(
            'Schéma extrakce [%s] neexistuje. Registrovaná schémata: %s.',
            $name,
            $known === [] ? '(žádné)' : implode(', ', $known),
        ));
    }
}
