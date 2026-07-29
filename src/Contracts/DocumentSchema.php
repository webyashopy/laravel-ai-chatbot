<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Contracts;

/**
 * Kontrakt schématu extrakce — popisuje, CO se má z dokumentu vytáhnout.
 *
 * Schémata dodává HOST (faktura, občanský průkaz, dodací list, smlouva…) —
 * balíček doménu nezná, jen je registruje: discovery nad
 * `chatbot.documents.schemas.discover_paths`, případně explicitně přes
 * {@see \Webyashopy\Chatbot\Chatbot::registerDocumentSchema()}. Stejný vzor
 * jako {@see ChatTool} a {@see ChatActionHandler} (ADR-019 §6) — přidání
 * schématu je nový soubor, žádná editace sdíleného seznamu.
 *
 * Pohodlnější než implementovat rozhraní přímo je podědit
 * {@see \Webyashopy\Chatbot\Support\BaseDocumentSchema}, který dodá rozumné
 * defaulty pro `label()`, `instructions()` a `transform()`.
 *
 * OMEZENÍ JSON SCHEMA (Anthropic structured outputs) — schéma z
 * {@see jsonSchema()} jde do API jako `output_config.format`, které NEPODPORUJE:
 *  - rekurzivní schémata (`$ref` na sebe sama),
 *  - číselné meze (`minimum`, `maximum`, `multipleOf`),
 *  - délky řetězců (`minLength`, `maxLength`),
 *  - `additionalProperties` s jinou hodnotou než `false`.
 * Podporuje: základní typy, `enum`, `const`, `anyOf`, `allOf`, `$ref`/`$def`
 * a formáty (`date`, `date-time`, `email`, `uri`, `uuid`…).
 *
 * Validaci rozsahů proto dělej až v {@see transform()} nebo ve validaci hosta,
 * ne ve schématu — jinak API vrátí 422/400.
 *
 * KONVENCE POVINNÝCH POLÍ: extraktor doplní `additionalProperties: false`
 * všem objektům a — pokud `required` ve schématu VŮBEC neuvedeš — vyplní ho
 * všemi deklarovanými vlastnostmi. Model tak musí odpovědět na každé pole
 * a nemůže si tiše vymýšlet klíče navíc. Nepovinný údaj se proto zapisuje
 * jako nullable typ (`'type' => ['string', 'null']`), NE vynecháním z `required`.
 * Uvedeš-li `required` explicitně, extraktor ho nechá být.
 */
interface DocumentSchema
{
    /**
     * Klíč schématu, kterým se extrakce volá (např. 'faktura').
     *
     * Ukládá se do `document_extractions.schema`, takže ho neměň bez
     * migrace dat — historické extrakce by se přestaly párovat.
     */
    public function name(): string;

    /**
     * Lidský název pro UI hosta (např. 'Faktura přijatá').
     */
    public function label(): string;

    /**
     * Popis pro model: co je to za dokument a co z něj chceme.
     * Jde do promptu, takže piš konkrétně („Přijatá faktura od dodavatele.“).
     */
    public function description(): string;

    /**
     * JSON Schema extrahovaných dat (viz omezení v docblocku rozhraní).
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array;

    /**
     * Doplňující pokyny k extrakci — formáty datumů, měna, jak řešit
     * nečitelné údaje. Prázdný string = žádné doplňky.
     */
    public function instructions(): string;

    /**
     * Postprocessing surových dat z modelu (převod formátů, normalizace
     * IČO, dopočet částek). Volá se PO validaci schématem.
     *
     * @param  array<string, mixed>  $data  Data přesně podle {@see jsonSchema()}.
     * @return array<string, mixed>
     */
    public function transform(array $data): array;
}
