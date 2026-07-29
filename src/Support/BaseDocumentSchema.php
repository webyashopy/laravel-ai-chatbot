<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Support;

use Illuminate\Support\Str;
use Webyashopy\Chatbot\Contracts\DocumentSchema;

/**
 * Volitelný předek schémat extrakce — dodá defaulty pro nepovinné části
 * {@see DocumentSchema}, aby host implementoval jen to podstatné:
 * {@see DocumentSchema::name()}, {@see DocumentSchema::description()}
 * a {@see DocumentSchema::jsonSchema()}.
 *
 * Příklad v host aplikaci (`app/Services/Ai/Documents/FakturaSchema.php`):
 *
 *     final class FakturaSchema extends BaseDocumentSchema
 *     {
 *         public function name(): string
 *         {
 *             return 'faktura';
 *         }
 *
 *         public function description(): string
 *         {
 *             return 'Přijatá faktura od dodavatele.';
 *         }
 *
 *         public function jsonSchema(): array
 *         {
 *             return [
 *                 'type' => 'object',
 *                 'properties' => [
 *                     'cislo' => ['type' => 'string', 'description' => 'Číslo faktury'],
 *                     'ico_dodavatele' => ['type' => ['string', 'null'], 'description' => 'IČO dodavatele, 8 číslic'],
 *                     'datum_splatnosti' => ['type' => ['string', 'null'], 'format' => 'date'],
 *                     'castka_bez_dph' => ['type' => 'number'],
 *                 ],
 *             ];
 *         }
 *
 *         public function instructions(): string
 *         {
 *             return 'Částky vracej jako číslo bez měny a bez oddělovačů tisíců. Datumy v ISO formátu RRRR-MM-DD.';
 *         }
 *     }
 */
abstract class BaseDocumentSchema implements DocumentSchema
{
    /**
     * Odvodí čitelný název z {@see DocumentSchema::name()}
     * ('faktura_prijata' → 'Faktura prijata'). Přepiš, pokud chceš
     * v UI přesný název včetně diakritiky.
     */
    public function label(): string
    {
        return Str::headline($this->name());
    }

    /**
     * Bez doplňujících pokynů — stačí popis a schéma.
     */
    public function instructions(): string
    {
        return '';
    }

    /**
     * Bez postprocessingu — data se vrací tak, jak je vrátil model.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transform(array $data): array
    {
        return $data;
    }
}
