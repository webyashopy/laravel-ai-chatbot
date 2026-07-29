<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Support;

/**
 * Zjištění počtu stran PDF BEZ externí závislosti (smalot/pdfparser ani
 * `pdfinfo` z poppler-utils) — balíček nesmí nutit host aplikaci
 * instalovat systémový binárník kvůli jednomu údaji.
 *
 * Počet stran potřebujeme jen jako STROP nákladů (`chatbot.documents.max_pages`),
 * ne pro renderování — proto stačí heuristika nad syntaxí souboru:
 *
 *  1. `/Count N` v uzlu stromu stránek (`/Type /Pages`) — nejspolehlivější,
 *     funguje i pro PDF 1.5+ s komprimovanými object streamy. Bere se
 *     MAXIMUM, protože kořen stromu má vždy součet všech podstromů.
 *  2. Fallback: počet objektů `/Type /Page` (bez koncového `s`) — sedí
 *     u nekomprimovaných PDF, kde se `/Count` nenajde.
 *
 * Když neuspěje ani jedno, vrací `null` = „nezjištěno". Volající pak limit
 * stran nevynucuje a spoléhá na limit velikosti souboru
 * ({@see \Webyashopy\Chatbot\Services\DocumentService}), který platí vždy.
 */
final class PdfInspector
{
    /**
     * Je obsah opravdu PDF? Kontrola magic bytes `%PDF-`.
     *
     * Hlavička nemusí být úplně na začátku — norma připouští pár bajtů
     * smetí před ní (typicky po chybném přenosu), proto se hledá
     * v prvních 1024 bajtech.
     */
    public function isPdf(string $contents): bool
    {
        return str_contains(substr($contents, 0, 1024), '%PDF-');
    }

    /**
     * Počet stran, nebo `null` když ho ze souboru nelze odvodit.
     */
    public function pageCount(string $contents): ?int
    {
        $fromTree = $this->pageCountFromPagesTree($contents);

        if ($fromTree !== null) {
            return $fromTree;
        }

        return $this->pageCountFromPageObjects($contents);
    }

    /**
     * `/Count N` u uzlů `/Type /Pages`. Klíče mohou být v libovolném
     * pořadí (`/Count 12 /Type /Pages` i naopak), proto se hledají obě
     * varianty a bere se největší nalezená hodnota (kořen stromu).
     */
    private function pageCountFromPagesTree(string $contents): ?int
    {
        $patterns = [
            '#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)#s',
            '#/Count\s+(\d+)[^>]*?/Type\s*/Pages\b#s',
        ];

        $max = null;

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $contents, $matches) === false) {
                continue;
            }

            foreach ($matches[1] ?? [] as $count) {
                $value = (int) $count;

                if ($value > 0 && ($max === null || $value > $max)) {
                    $max = $value;
                }
            }
        }

        return $max;
    }

    /**
     * Fallback: počet objektů `/Type /Page`.
     *
     * `(?!s)` je nutné — bez něj by se napočítaly i uzly `/Type /Pages`
     * a výsledek by byl nadsazený.
     */
    private function pageCountFromPageObjects(string $contents): ?int
    {
        $found = preg_match_all('#/Type\s*/Page(?![s/\w])#', $contents);

        if ($found === false || $found === 0) {
            return null;
        }

        return $found;
    }
}
