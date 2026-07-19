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

        // Striktní režim per-user klíčů: true = volání S uživatelem, který nemá
        // vlastní klíč v `user_ai_settings`, se odmítne (MissingUserApiKeyException)
        // — serverový klíč výše pak slouží JEN systémovým voláním bez usera
        // (např. `chatbot:models-check`). Default false = dnešní chování
        // (fallback user → env) kvůli zpětné kompatibilitě hostů.
        'require_user_key' => (bool) env('CHATBOT_REQUIRE_USER_KEY', false),
    ],

    // Výchozí model nové konverzace (musí být v allowlistu 'models' níže).
    // POZOR: samostatný env od 'model' níže. Host typicky chce silný model na chat
    // a levný na complete()/OCR; sdílený env by tuhle volbu zabil (nález verify TASK-091).
    'default_model' => env('CHATBOT_CHAT_MODEL', env('CHATBOT_MODEL', 'claude-sonnet-5')),

    // Výchozí model pro jednorázové volání complete() (např. OCR v hostovi).
    // Default je ZÁMĚRNĚ levný — complete() je typicky vysokoobjemová extrakce, ne konverzace.
    // JNS mělo ocr.model = haiku; kdyby tu byl sonnet, překlopení OCR (TASK-092) by tiše
    // zdražilo každé volání ~3,5x. Volající smí model přepsat per-volání.
    'model' => env('CHATBOT_COMPLETE_MODEL', env('CHATBOT_MODEL', 'claude-haiku-4-5')),

    // Allowlist modelů dostupných pro chat (přepínání). Mimo allowlist → 422.
    'models' => [
        'claude-sonnet-5',
        'claude-haiku-4-5',
        'claude-opus-4-8',
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
        // Kurz ČNB 2026-06-28: 1 USD = 21,28 CZK. USD ceny (Anthropic, 2026-07-16):
        // Sonnet 5 $3/$15, Opus 4.8 $5/$25, Haiku 4.5 $1/$5 za Mtok.
        'claude-sonnet-5' => ['input' => 63.84, 'output' => 319.2],
        'claude-haiku-4-5' => ['input' => 21.28, 'output' => 106.4],
        'claude-opus-4-8' => ['input' => 106.4, 'output' => 532.0],
    ],

    // Retry/backoff volání Anthropic API (exponenciální — delay_ms * multiplier^n).
    'retry' => [
        'max_attempts' => (int) env('CHATBOT_RETRY_MAX_ATTEMPTS', 3),
        'delay_ms' => (int) env('CHATBOT_RETRY_DELAY_MS', 1000),
        'multiplier' => (int) env('CHATBOT_RETRY_MULTIPLIER', 2),
    ],

    // Timeouty HTTP klienta (sekundy).
    'timeouts' => [
        'request' => (int) env('CHATBOT_HTTP_TIMEOUT', 60),
        'connect' => (int) env('CHATBOT_HTTP_CONNECT_TIMEOUT', 10),
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
    | Env-driven záměrně: v JNS byly limity přepínatelné přes OCR_RATE_PER_MINUTE
    | a AI_CHAT_RATE_PER_MINUTE. Hardcode by tichým způsobem zahodil produkční
    | override (nález verify TASK-091) — defaulty odpovídají dnešnímu JNS.
    |
    */
    'rate' => [
        'per_purpose' => [
            'chat' => (int) env('CHATBOT_RATE_CHAT', 20),
            'ocr' => (int) env('CHATBOT_RATE_OCR', 10),
        ],
        'default' => (int) env('CHATBOT_RATE_DEFAULT', 10),

        // VOLITELNÝ globální strop (TASK-103) — mapa purpose => limit/min NAPŘÍČ
        // celou aplikací (bucket bez userId), platí SOUČASNĚ s per_purpose výše
        // (musí projít oba). Prázdné pole (default) = vypnuto, chování beze změny
        // (zpětná kompatibilita). Host si zapíná per účel — JNS ho používá pro
        // 'ocr', protože per-user limit sám o sobě neomezí souhrnnou útratu
        // napříč všemi uživateli (bezpečnostní audit 2026-07-15).
        'global_per_purpose' => [],
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
                'claude-sonnet-5',
                'claude-haiku-4-5',
                'claude-opus-4-8',
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
