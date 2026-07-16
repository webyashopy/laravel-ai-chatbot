<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Services;

use Illuminate\Support\Facades\Log;
use Throwable;
use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Contracts\ChatTool;
use Webyashopy\Chatbot\Support\HostClassLocator;

/**
 * Registr nástrojů chatbota (ADR-017) — SELF-DISCOVERY, ne ruční seznam.
 *
 * Prohledá adresáře z `config('chatbot.tools.discover_paths')` (rekurzivně)
 * a najde všechny třídy implementující {@see ChatTool}. Přidání nového
 * nástroje = nová třída v některém z těchto adresářů (klidně v podadresáři,
 * např. `Tools/Read/`) — registr ani žádný jiný sdílený soubor se needituje,
 * takže dva tasky mohou přidávat nástroje SOUBĚŽNĚ bez kolize v gitu.
 * Ruční seznam v configu je zamítnutý (ADR-019, alternativa 4).
 *
 * Oproti původní registry v JNS nejsou cesta ani namespace natvrdo:
 * cesty jdou z configu hosta a namespace se odvodí z PSR-4 mapy composeru
 * ({@see HostClassLocator}). Sken běží jen nad adresáři HOSTA, nikdy nad
 * `vendor/`.
 *
 * Doplňkem discovery je explicitní registrace pro třídy mimo prohledávané
 * cesty: `Chatbot::registerTool(ReadFakturyTool::class)`.
 *
 * Vypnutí `config('chatbot.chat.tools.enabled')` vrátí prázdný registr
 * (žádné nástroje se nepošlou modelu ani neprovedou) — kill-switch bez
 * zásahu do kódu.
 */
class ChatToolRegistry
{
    /** @var array<string, ChatTool>|null Cache nálezu pro tuto instanci (jeden scan disku). */
    private ?array $tools = null;

    public function __construct(
        private readonly HostClassLocator $locator = new HostClassLocator,
    ) {}

    /**
     * @return array<int, ChatTool>
     */
    public function all(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_values($this->discover());
    }

    /**
     * Najde nástroj podle `tool_use.name`, nebo `null` (volající vrátí tool_result chybu).
     */
    public function get(string $name): ?ChatTool
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->discover()[$name] ?? null;
    }

    /**
     * Anthropic `tools` pole pro tělo requestu
     * ({@see \Webyashopy\Chatbot\Services\AiService::converse()}).
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_map(static fn (ChatTool $tool): array => $tool->definition(), $this->all());
    }

    /**
     * Kill-switch — vypnuté nástroje znamenají prázdný registr.
     */
    private function enabled(): bool
    {
        return (bool) config('chatbot.chat.tools.enabled', true);
    }

    /**
     * Sesbírá nástroje z discovery + explicitní registrace a vrátí mapu
     * `name() => instance`. Instanciace jde přes kontejner (nástroje si
     * smějí injectovat závislosti hosta).
     *
     * @return array<string, ChatTool>
     */
    private function discover(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        /** @var array<int, class-string<ChatTool>> $classes */
        $classes = array_unique(array_merge(
            $this->locator->locate($this->paths(), ChatTool::class),
            Chatbot::registeredTools(),
        ));

        $tools = [];

        foreach ($classes as $class) {
            try {
                /** @var ChatTool $tool */
                $tool = app($class);
            } catch (Throwable $e) {
                // Nástroj, který kontejner neumí sestavit (např. skalár bez
                // defaultu v konstruktoru), nesmí shodit celý chat — přidání
                // souboru je podle kontraktu bezpečná operace. Vadný nástroj
                // přeskočíme a necháme stopu v logu.
                Log::warning('Chatbot: nástroj nejde instanciovat, přeskakuje se.', [
                    'tool' => $class,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $tools[$tool->name()] = $tool;
        }

        return $this->tools = $tools;
    }

    /**
     * Prohledávané adresáře hosta.
     *
     * @return array<int, mixed>
     */
    private function paths(): array
    {
        return (array) config('chatbot.tools.discover_paths', []);
    }
}
