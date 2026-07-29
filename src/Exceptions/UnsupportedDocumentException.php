<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Exceptions;

use RuntimeException;

/**
 * Nahraný soubor není podporovaný typ dokumentu.
 *
 * MIME se určuje z OBSAHU souboru (finfo), ne z přípony ani z hlavičky
 * od klienta — přejmenovaný `.pdf` skončí právě tady.
 */
final class UnsupportedDocumentException extends RuntimeException
{
    /**
     * @param  array<int, string>  $allowed
     */
    public static function mimeType(string $mimeType, array $allowed): self
    {
        return new self(sprintf(
            'Typ souboru [%s] není podporovaný. Povolené typy: %s.',
            $mimeType,
            implode(', ', $allowed),
        ));
    }

    public static function unreadable(string $path): self
    {
        return new self("Soubor [{$path}] neexistuje nebo ho nelze přečíst.");
    }

    public static function corruptedPdf(): self
    {
        return new self('Soubor se tváří jako PDF, ale nemá platnou hlavičku %PDF-.');
    }
}
