<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Support;

use Illuminate\Support\Arr;

/**
 * Výsledek extrakce dat z dokumentu — neměnný value object.
 *
 * Vrací ho {@see \Webyashopy\Chatbot\Services\DocumentService::extract()}
 * a je to hranice mezi balíčkem a doménou hosta: {@see data()} je asociativní
 * pole přesně podle `jsonSchema()` použitého schématu, takže se dá rovnou
 * předhodit formuláři (`$request->merge()`, Inertia props, `fill()` modelu).
 */
final class ExtractionResult
{
    /**
     * @param  array<string, mixed>  $data  Extrahovaná data po `transform()` schématu.
     * @param  array<string, mixed>  $usage  Usage blok Anthropic API (tokeny).
     * @param  float|null  $cost  Cena v CZK; `null` u neznámého modelu (viz ADR-015).
     * @param  bool  $cached  `true` = vráceno z DB, žádné volání API neproběhlo.
     */
    public function __construct(
        private readonly array $data,
        private readonly string $schema,
        private readonly string $model,
        private readonly array $usage = [],
        private readonly ?float $cost = null,
        private readonly bool $cached = false,
        private readonly ?int $extractionId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * Jedna hodnota tečkovou notací (`polozky.0.nazev`), s defaultem
     * pro chybějící klíč — model smí vracet `null` u nenalezených údajů.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    /**
     * Název schématu, kterým se extrahovalo.
     */
    public function schema(): string
    {
        return $this->schema;
    }

    /**
     * Model, který extrakci provedl (u `cached` výsledku ten původní).
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        return $this->usage;
    }

    public function cost(): ?float
    {
        return $this->cost;
    }

    /**
     * Přišel výsledek z DB bez volání API? Užitečné pro UI („načteno
     * z dřívější extrakce") i pro testy nákladů.
     */
    public function wasCached(): bool
    {
        return $this->cached;
    }

    /**
     * ID řádku `document_extractions`; `null` jen když se výsledek
     * neukládal (přímé volání extraktoru mimo {@see \Webyashopy\Chatbot\Services\DocumentService}).
     */
    public function extractionId(): ?int
    {
        return $this->extractionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'schema' => $this->schema,
            'model' => $this->model,
            'usage' => $this->usage,
            'cost' => $this->cost,
            'cached' => $this->cached,
            'extraction_id' => $this->extractionId,
        ];
    }
}
