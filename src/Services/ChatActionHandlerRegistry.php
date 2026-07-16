<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Services;

use Illuminate\Support\Facades\Log;
use Throwable;
use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Contracts\ChatActionHandler;
use Webyashopy\Chatbot\Support\HostClassLocator;

/**
 * Registr handlerů potvrzení akcí — stejný mechanismus jako
 * {@see ChatToolRegistry}, jen nad `config('chatbot.actions.discover_paths')`
 * a kontraktem {@see ChatActionHandler}.
 *
 * Nahrazuje doménový `match ($action['kind'])` v controlleru: balíček najde
 * handler podle `kind()` a předá mu payload návrhu. Přidání nového druhu
 * akce = nová třída v prohledávaném adresáři hosta, bez editace sdíleného
 * souboru. Doplňkem je `Chatbot::registerActionHandler(...)`.
 *
 * POZOR: kill-switch `chatbot.chat.tools.enabled` se zde ZÁMĚRNĚ neuplatňuje.
 * Vypnuté nástroje znamenají, že nevzniknou nové návrhy — ale už rozpracovaný
 * návrh v existující konverzaci musí jít stále potvrdit (a potvrzení je akce
 * uživatele, ne modelu).
 */
class ChatActionHandlerRegistry
{
    /** @var array<string, ChatActionHandler>|null Cache nálezu pro tuto instanci. */
    private ?array $handlers = null;

    public function __construct(
        private readonly HostClassLocator $locator = new HostClassLocator,
    ) {}

    /**
     * @return array<int, ChatActionHandler>
     */
    public function all(): array
    {
        return array_values($this->discover());
    }

    /**
     * Najde handler podle `kind` návrhu, nebo `null` (volající vrátí chybu —
     * neznámý druh akce se nikdy nesmí zapsat).
     */
    public function get(string $kind): ?ChatActionHandler
    {
        return $this->discover()[$kind] ?? null;
    }

    /**
     * Druhy akcí, které host umí potvrdit.
     *
     * @return array<int, string>
     */
    public function kinds(): array
    {
        return array_keys($this->discover());
    }

    /**
     * @return array<string, ChatActionHandler>
     */
    private function discover(): array
    {
        if ($this->handlers !== null) {
            return $this->handlers;
        }

        /** @var array<int, class-string<ChatActionHandler>> $classes */
        $classes = array_unique(array_merge(
            $this->locator->locate((array) config('chatbot.actions.discover_paths', []), ChatActionHandler::class),
            Chatbot::registeredActionHandlers(),
        ));

        $handlers = [];

        foreach ($classes as $class) {
            try {
                /** @var ChatActionHandler $handler */
                $handler = app($class);
            } catch (Throwable $e) {
                // Vadný handler nesmí shodit potvrzování ostatních akcí —
                // neznámý `kind` volající stejně odmítne (nikdy nezapíše).
                Log::warning('Chatbot: handler akce nejde instanciovat, přeskakuje se.', [
                    'handler' => $class,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $handlers[$handler->kind()] = $handler;
        }

        return $this->handlers = $handlers;
    }
}
