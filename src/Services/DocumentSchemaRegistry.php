<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Services;

use Illuminate\Support\Facades\Log;
use Throwable;
use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Contracts\DocumentSchema;
use Webyashopy\Chatbot\Support\HostClassLocator;

/**
 * Registr schémat extrakce — SELF-DISCOVERY, ne ruční seznam.
 *
 * Prohledá adresáře z `config('chatbot.documents.schemas.discover_paths')`
 * (rekurzivně) a najde třídy implementující {@see DocumentSchema}. Přidání
 * nového typu dokumentu = nová třída v tom adresáři; registr ani config se
 * needituje, takže dva tasky mohou přidávat schémata souběžně bez kolize
 * v gitu. Stejná mechanika jako {@see ChatToolRegistry} (ADR-019 §6).
 *
 * Doplňkem je explicitní registrace pro třídy mimo prohledávané cesty:
 * `Chatbot::registerDocumentSchema(FakturaSchema::class)`.
 *
 * Vypnutá feature `chatbot.features.documents` vrací prázdný registr —
 * kill-switch bez zásahu do kódu.
 */
class DocumentSchemaRegistry
{
    /** @var array<string, DocumentSchema>|null Cache nálezu pro tuto instanci (jeden scan disku). */
    private ?array $schemas = null;

    public function __construct(
        private readonly HostClassLocator $locator = new HostClassLocator,
    ) {}

    /**
     * @return array<int, DocumentSchema>
     */
    public function all(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_values($this->discover());
    }

    /**
     * Schéma podle názvu, nebo `null` (volající vyhodí
     * {@see \Webyashopy\Chatbot\Exceptions\UnknownDocumentSchemaException}).
     */
    public function get(string $name): ?DocumentSchema
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->discover()[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Názvy registrovaných schémat — pro chybové hlášky a výběr v UI hosta.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->enabled() ? $this->discover() : []);
    }

    /**
     * Mapa `name => label` pro select ve formuláři hosta.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->all() as $schema) {
            $options[$schema->name()] = $schema->label();
        }

        return $options;
    }

    /**
     * Kill-switch — vypnutá feature znamená prázdný registr.
     */
    private function enabled(): bool
    {
        return (bool) config('chatbot.features.documents', true);
    }

    /**
     * Sesbírá schémata z discovery + explicitní registrace a vrátí mapu
     * `name() => instance`. Instanciace jde přes kontejner (schéma si smí
     * injectovat závislosti hosta, např. číselník dodavatelů).
     *
     * @return array<string, DocumentSchema>
     */
    private function discover(): array
    {
        if ($this->schemas !== null) {
            return $this->schemas;
        }

        /** @var array<int, class-string<DocumentSchema>> $classes */
        $classes = array_unique(array_merge(
            $this->locator->locate($this->paths(), DocumentSchema::class),
            Chatbot::registeredDocumentSchemas(),
        ));

        $schemas = [];

        foreach ($classes as $class) {
            try {
                /** @var DocumentSchema $schema */
                $schema = app($class);
            } catch (Throwable $e) {
                // Vadné schéma nesmí shodit celou digitalizaci — přidání
                // souboru je podle kontraktu bezpečná operace. Přeskočíme
                // ho a necháme stopu v logu (vzor ChatToolRegistry).
                Log::warning('Chatbot: schéma dokumentu nejde instanciovat, přeskakuje se.', [
                    'schema' => $class,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $schemas[$schema->name()] = $schema;
        }

        return $this->schemas = $schemas;
    }

    /**
     * Prohledávané adresáře hosta.
     *
     * @return array<int, mixed>
     */
    private function paths(): array
    {
        return (array) config('chatbot.documents.schemas.discover_paths', []);
    }
}
