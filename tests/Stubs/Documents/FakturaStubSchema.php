<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Stubs\Documents;

use Webyashopy\Chatbot\Support\BaseDocumentSchema;

/**
 * Testovací schéma extrakce.
 *
 * ZÁMĚRNĚ bez `required` a bez `additionalProperties` — testy nad ním
 * ověřují, že je do schématu doplní extraktor
 * ({@see \Webyashopy\Chatbot\Services\DocumentExtractor}), včetně vnořeného
 * objektu v `items`.
 */
final class FakturaStubSchema extends BaseDocumentSchema
{
    public function name(): string
    {
        return 'faktura';
    }

    public function label(): string
    {
        return 'Faktura přijatá';
    }

    public function description(): string
    {
        return 'Přijatá faktura od dodavatele.';
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cislo' => ['type' => 'string'],
                'castka_bez_dph' => ['type' => 'number'],
                'polozky' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nazev' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function instructions(): string
    {
        return 'Částky vracej jako číslo bez měny.';
    }

    /**
     * Dopočet DPH — v testech slouží jako důkaz, že postprocessing
     * proběhl (klíč `castka_s_dph` z modelu nikdy nepřijde).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transform(array $data): array
    {
        $data['castka_s_dph'] = round((float) ($data['castka_bez_dph'] ?? 0) * 1.21, 2);

        return $data;
    }
}
