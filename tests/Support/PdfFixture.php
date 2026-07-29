<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Support;

/**
 * Generátor minimálních, ale syntakticky platných PDF pro testy.
 *
 * Musí projít dvěma kontrolami balíčku naráz:
 *  - `finfo` ho určí jako `application/pdf` (hlavička `%PDF-`),
 *  - {@see \Webyashopy\Chatbot\Support\PdfInspector} z něj vyčte počet stran.
 */
final class PdfFixture
{
    /**
     * PDF se stromem stránek nesoucím `/Count` i odpovídajícím počtem
     * objektů `/Type /Page` — tedy čitelné oběma heuristikami inspektoru.
     */
    public static function withPages(int $pages): string
    {
        $pdf = "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Count {$pages} /Kids [] >>\nendobj\n";

        for ($i = 1; $i <= $pages; $i++) {
            $objectNumber = $i + 2;
            $pdf .= "{$objectNumber} 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\n";
        }

        return $pdf."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * PDF BEZ zjistitelného počtu stran — simuluje soubor s komprimovanými
     * object streamy (PDF 1.5+), kde heuristika inspektoru neuspěje.
     */
    public static function withUnknownPageCount(): string
    {
        return "%PDF-1.5\n1 0 obj\n<< /Type /ObjStm /N 4 >>\nstream\nbinarni-data\nendstream\nendobj\n%%EOF\n";
    }
}
