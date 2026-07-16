<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot;

use InvalidArgumentException;
use Webyashopy\Chatbot\Contracts\ChatActionHandler;
use Webyashopy\Chatbot\Contracts\ChatTool;

/**
 * Statický registr nástrojů a action handlerů balíčku.
 *
 * Primární cestou registrace je discovery nad adresáři host aplikace
 * (`chatbot.tools.discover_paths` / `chatbot.actions.discover_paths`) —
 * to řeší TASK-093. Tento registr je explicitní API pro okrajové případy,
 * kdy třída leží mimo prohledávané cesty:
 *
 *     Chatbot::registerTool(ReadFakturyTool::class);
 *     Chatbot::registerActionHandler(PartnerActionHandler::class);
 *
 * Ukládají se pouze názvy tříd (class-string), ne instance — instanciaci
 * a resolve závislostí provede až konzument přes kontejner.
 */
final class Chatbot
{
    /**
     * Ručně registrované nástroje.
     *
     * @var array<int, class-string<ChatTool>>
     */
    private static array $tools = [];

    /**
     * Ručně registrované action handlery.
     *
     * @var array<int, class-string<ChatActionHandler>>
     */
    private static array $actionHandlers = [];

    /**
     * Třída je jen statický registr — instance nedávají smysl.
     */
    private function __construct() {}

    /**
     * Zaregistruje nástroj (třídu implementující {@see ChatTool}).
     *
     * Opakovaná registrace téže třídy je no-op (idempotence — provider
     * host aplikace se může bootovat vícekrát, např. v testech).
     *
     * @param  class-string<ChatTool>  $tool
     *
     * @throws InvalidArgumentException Pokud třída kontrakt neimplementuje.
     */
    public static function registerTool(string $tool): void
    {
        self::guardImplements($tool, ChatTool::class);

        if (! in_array($tool, self::$tools, true)) {
            self::$tools[] = $tool;
        }
    }

    /**
     * Zaregistruje handler potvrzení akce ({@see ChatActionHandler}).
     *
     * @param  class-string<ChatActionHandler>  $handler
     *
     * @throws InvalidArgumentException Pokud třída kontrakt neimplementuje.
     */
    public static function registerActionHandler(string $handler): void
    {
        self::guardImplements($handler, ChatActionHandler::class);

        if (! in_array($handler, self::$actionHandlers, true)) {
            self::$actionHandlers[] = $handler;
        }
    }

    /**
     * Ručně registrované nástroje.
     *
     * @return array<int, class-string<ChatTool>>
     */
    public static function registeredTools(): array
    {
        return self::$tools;
    }

    /**
     * Ručně registrované action handlery.
     *
     * @return array<int, class-string<ChatActionHandler>>
     */
    public static function registeredActionHandlers(): array
    {
        return self::$actionHandlers;
    }

    /**
     * Vyprázdní registr — určeno pro testy (izolace mezi případy).
     */
    public static function flush(): void
    {
        self::$tools = [];
        self::$actionHandlers = [];
    }

    /**
     * Ověří, že třída existuje a implementuje očekávaný kontrakt.
     *
     * Chyba se hlásí hned při registraci (fail fast), ne až při běhu
     * agentní smyčky, kde by se projevila jako záhadný pád konverzace.
     *
     * @param  class-string  $class
     * @param  class-string  $contract
     */
    private static function guardImplements(string $class, string $contract): void
    {
        if (! is_subclass_of($class, $contract)) {
            throw new InvalidArgumentException(
                "Třída [{$class}] neimplementuje kontrakt [{$contract}]."
            );
        }
    }
}
