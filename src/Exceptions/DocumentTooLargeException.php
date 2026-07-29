<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Exceptions;

use RuntimeException;

/**
 * Dokument překračuje limit velikosti nebo počtu stran
 * (`chatbot.documents.max_size_mb` / `max_pages`).
 *
 * ZÁMĚRNĚ výjimka, ne tiché oříznutí: oříznutá faktura by se extrahovala
 * „úspěšně“ a chybějící položky by nikdo nepoznal.
 */
final class DocumentTooLargeException extends RuntimeException
{
    public static function bytes(int $bytes, int $maxBytes): self
    {
        return new self(sprintf(
            'Dokument má %.1f MB, povolené maximum je %.1f MB.',
            $bytes / 1_048_576,
            $maxBytes / 1_048_576,
        ));
    }

    public static function pages(int $pages, int $maxPages): self
    {
        return new self("Dokument má {$pages} stran, povolené maximum je {$maxPages}.");
    }
}
