<?php

declare(strict_types=1);

/*
 * Konfigurace balíčku webyashopy/laravel-ai-chatbot.
 *
 * Struktura dle config patternu balíčků (klíče `models` / `features` /
 * `routes`) + doménové bloky AI vrstvy (api / retry / timeouts / rate /
 * chat / tools / actions / prompts). Zdroj pravdy:
 * contracts/api/chatbot-package.md (ADR-019).
 *
 * POZOR: žádné closures — `config:cache` je neumí serializovat. Autorizace
 * a rozšíření proto jdou přes bindované kontrakty (ChatAuthorizer,
 * ChatTool, ChatActionHandler), ne přes config closure.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | User model hosta
    |--------------------------------------------------------------------------
    |
    | Model uživatele, který dodává host aplikace. Balíček si User model
    | nikdy neimportuje přímo — vždy ho čte přes tento config (proto jsou
    | i parametry `$user` v kontraktech typované `mixed`).
    |
    | ODCHYLKA od contracts/api/chatbot-package.md: kontrakt uvádí
    | `models.user_model` (vzor `tickets.php`) a ZÁROVEŇ `models` jako
    | allowlist AI modelů — v PHP je to jeden a týž klíč, druhý zápis by
    | ten první tiše přepsal. Allowlist zůstává na `chatbot.models`
    | (tak ho popisuje mapa migrace `ai.models` → `chatbot.models` a čte
    | ho runtime kontrola 422), User model je proto zde, jako
    | `chatbot.user_model`. Env `CHATBOT_USER_MODEL` beze změny.
    |
    */
    'user_model' => env('CHATBOT_USER_MODEL', 'App\Models\User'),

    /*
    |--------------------------------------------------------------------------
    | Přepínače funkcí
    |--------------------------------------------------------------------------
    |
    |  - chat: false = jen AI vrstva (klient + usage logging + per-user klíče);
    |    chat routy, modely konverzací a tool-loop se neregistrují (ADR-019 §9).
    |
    */
    'features' => [
        'chat' => (bool) env('CHATBOT_FEATURE_CHAT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routy
    |--------------------------------------------------------------------------
    |
    | Prefix, middleware a pojmenování (`as`) rout balíčku. Host aplikace
    | upraví dle vlastní routovací struktury (JNS používá
    | ['web','auth','verified','can:chat.use']).
    |
    | `as` NEMĚNIT — frontend a Wayfinder stojí na názvech `chat.*`
    | (ADR-019 §11).
    |
    */
    'routes' => [
        'prefix' => env('CHATBOT_ROUTE_PREFIX', 'chat'),
        'middleware' => ['web', 'auth'],
        'as' => 'chat.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic API
    |--------------------------------------------------------------------------
    |
    | Env názvy zůstávají `ANTHROPIC_*` — .env host aplikace se extrakcí
    | nemění (dřívější `services.anthropic.*`).
    |
    */
    'api' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1'),
        'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    ],

    // Výchozí model nové konverzace (musí být v allowlistu 'models' níže).
    'default_model' => env('CHATBOT_MODEL', 'claude-sonnet-4-5-20250929'),

    // Výchozí model pro jednorázové volání complete() (např. OCR v hostovi).
    'model' => env('CHATBOT_MODEL', 'claude-sonnet-4-5-20250929'),

    // Allowlist modelů dostupných pro chat (přepínání). Mimo allowlist → 422.
    'models' => [
        'claude-sonnet-4-5-20250929',
        'claude-haiku-4-5-20251001',
        'claude-opus-4-1-20250805',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ceník
    |--------------------------------------------------------------------------
    |
    | Cena za 1 milion tokenů (Mtok) v CZK — zdroj pro výpočet
    | `ai_usage_logs.cost`. Ceny jsou orientační, aktualizuj dle ceníku
    | Anthropic a kurzu CZK/USD. Neznámý model při logování → cost null
    | + varování (ne pád), viz ADR-015.
    |
    */
    'pricing' => [
        'claude-sonnet-4-5-20250929' => ['input' => 70.0, 'output' => 350.0],
        'claude-haiku-4-5-20251001' => ['input' => 20.0, 'output' => 100.0],
        'claude-opus-4-1-20250805' => ['input' => 350.0, 'output' => 1750.0],
    ],

    // Retry/backoff volání Anthropic API (exponenciální — delay_ms * multiplier^n).
    'retry' => [
        'max_attempts' => 3,
        'delay_ms' => 1000,
        'multiplier' => 2,
    ],

    // Timeouty HTTP klienta (sekundy).
    'timeouts' => [
        'request' => 60,
        'connect' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limit per purpose
    |--------------------------------------------------------------------------
    |
    | `purpose` je volný string (ADR-019 §3) — balíček používá
    | Support\Purpose::CHAT, host si předá vlastní (např. 'ocr').
    | Limit je počet volání za minutu na uživatele; neznámý purpose
    | spadne na 'default'.
    |
    */
    'rate' => [
        'per_purpose' => [
            'chat' => 20,
            'ocr' => 10,
        ],
        'default' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    */
    'chat' => [
        // Kolik posledních zpráv konverzace se posílá jako historie do promptu.
        'history_limit' => (int) env('CHATBOT_HISTORY_LIMIT', 20),

        // Tool-use nad daty hosta (ADR-017) — kill-switch a limit iterací.
        'tools' => [
            // false = registr nevrátí žádné nástroje (chatbot bez přístupu k datům).
            'enabled' => (bool) env('CHATBOT_TOOLS_ENABLED', true),

            // Tvrdý limit round-tripů agentní smyčky jednoho dotazu (proti zacyklení).
            'max_iterations' => (int) env('CHATBOT_TOOLS_MAX_ITERATIONS', 5),

            // Modely podporující tool-use (ADR-017 §6). Model MIMO tento seznam
            // (i když je v allowlistu 'models') → fallback na complete()
            // (čistě textová odpověď, žádné nástroje) místo pádu.
            'capable_models' => [
                'claude-sonnet-4-5-20250929',
                'claude-haiku-4-5-20251001',
                'claude-opus-4-1-20250805',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery nástrojů a action handlerů
    |--------------------------------------------------------------------------
    |
    | Prohledávají se adresáře HOST aplikace (ne vendor/) — nástroje i
    | handlery domény zůstávají v hostovi (ADR-019 §6). Balíček zde hledá
    | třídy implementující Contracts\ChatTool, resp. Contracts\ChatActionHandler.
    |
    */
    'tools' => [
        'discover_paths' => [app_path('Services/Ai/Tools')],
    ],

    'actions' => [
        'discover_paths' => [app_path('Services/Ai/Actions')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompty
    |--------------------------------------------------------------------------
    |
    | Doménový kontext hosta, který se připojí k systémovému promptu.
    | Bezpečnostní preambule je FIXNÍ v balíčku a host ji nesmí přepsat
    | (ADR-019 §7) — zde se přidává pouze popis domény.
    |
    */
    'prompts' => [
        'context' => '',
    ],

];
