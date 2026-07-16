# webyashopy/laravel-ai-chatbot

Znovupoužitelný **AI chatbot (Anthropic Claude)** pro Laravel aplikace —
AI klient, usage logging, per-user API klíče a agentní tool-loop nad daty
host aplikace.

> **Status:** Kostra (TASK-090) — config, kontrakty a registr. AI klient,
> modely, controller a routy přijdou v TASK-091..094.

## Filozofie

Balíček veze **AI vrstvu a mechaniku chatu**, doména zůstává v hostovi:

| Vrstva | Kde žije |
|---|---|
| Anthropic klient (retry, timeouty, rate limit, usage logging, pricing) | balíček |
| Modely konverzací, agentní smyčka, registr nástrojů, controller, routy, migrace | balíček |
| Nástroje nad daty (`read_*`, `propose_*`), zápis do domény, audit | host |
| Frontend (chat widget, stránky) | host |

Balíček **nezná role** (žádný `spatie/laravel-permission`) ani host User
model — obojí jde přes kontrakty s parametrem `$user` typovaným `mixed`.

## Instalace

### 1) Composer

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/webyashopy/laravel-ai-chatbot" }
]
```

```bash
composer require webyashopy/laravel-ai-chatbot:^0.1
```

### 2) Publish konfigurace a migrace

```bash
php artisan vendor:publish --provider="Webyashopy\Chatbot\ChatbotServiceProvider"
php artisan migrate
```

Provider se registruje sám (`extra.laravel.providers`).

### 3) Env

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
# volitelně
ANTHROPIC_API_URL=https://api.anthropic.com/v1
ANTHROPIC_API_VERSION=2023-06-01
CHATBOT_MODEL=claude-sonnet-4-5-20250929
```

### 4) Vlastní autorizace (volitelné, doporučené)

Default pustí do chatu kohokoli přihlášeného. Aplikace s rolemi si ve svém
provideru nabinduje vlastní implementaci:

```php
// app/Providers/ChatbotServiceProvider.php
use Webyashopy\Chatbot\Contracts\ChatAuthorizer;

public function register(): void
{
    $this->app->bind(ChatAuthorizer::class, PermissionChatAuthorizer::class);
}
```

## Konfigurace (`config/chatbot.php`)

| Klíč | Default | Popis |
|---|---|---|
| `user_model` | `App\Models\User` | Model uživatele hosta (env `CHATBOT_USER_MODEL`) |
| `features.chat` | `true` | `false` = jen AI vrstva, bez chat rout a tool-loopu |
| `routes.prefix` | `chat` | Prefix rout balíčku (env `CHATBOT_ROUTE_PREFIX`) |
| `routes.middleware` | `['web','auth']` | Middleware rout; host přidá své (`can:chat.use`) |
| `routes.as` | `chat.` | Prefix názvů rout — **neměnit** (stojí na tom frontend) |
| `api.key` / `api.url` / `api.version` | env | Anthropic API (env `ANTHROPIC_*`) |
| `default_model` | `claude-sonnet-4-5-20250929` | Model nové konverzace (env `CHATBOT_MODEL`) |
| `model` | `claude-sonnet-4-5-20250929` | Model pro jednorázové `complete()` |
| `models` | 3 modely | Allowlist modelů pro chat; mimo něj → 422 |
| `pricing` | viz config | CZK / 1 Mtok (`input`/`output`) pro výpočet `cost` |
| `retry` | 3 / 1000 ms / ×2 | Retry a exponenciální backoff volání API |
| `timeouts` | 60 / 10 s | HTTP `request` / `connect` timeout |
| `rate.per_purpose` | `chat: 20`, `ocr: 10` | Limit volání/min na uživatele dle účelu |
| `rate.default` | `10` | Fallback pro neznámý účel |
| `chat.history_limit` | `20` | Kolik posledních zpráv jde do promptu |
| `chat.tools.enabled` | `true` | Kill-switch nástrojů |
| `chat.tools.max_iterations` | `5` | Tvrdý limit iterací agentní smyčky |
| `chat.tools.capable_models` | 3 modely | Modely umějící tool-use; jinak fallback na text |
| `tools.discover_paths` | `app/Services/Ai/Tools` | Kde se hledají `ChatTool` hosta |
| `actions.discover_paths` | `app/Services/Ai/Actions` | Kde se hledají `ChatActionHandler` hosta |
| `prompts.context` | `''` | Doménový kontext hosta (preambule je fixní v balíčku) |

Config **neobsahuje closures** — je cacheovatelný přes `config:cache`.

## Kontrakty

| Kontrakt | Účel | Default balíčku |
|---|---|---|
| `Contracts\ChatAuthorizer` | Kdo smí chat / potvrdit akci | `Support\AllowAuthenticatedChatAuthorizer` |
| `Contracts\ChatTool` | Nástroj nad daty hosta | — (dodává host) |
| `Contracts\ChatActionHandler` | Potvrzení navrženého zápisu | — (dodává host) |

Bindingy jsou přes `bind()`, ne `singleton()` — host je snadno přepíše.

### Registrace nástrojů a handlerů

Primárně discovery nad cestami z configu; explicitně pak:

```php
use Webyashopy\Chatbot\Chatbot;

Chatbot::registerTool(ReadFakturyTool::class);
Chatbot::registerActionHandler(PartnerActionHandler::class);
```

## Bezpečnostní invarianty

1. Každý tool handler se re-autorizuje pod přihlášeným uživatelem.
2. Žádný zápis do domény z nástroje — write nástroj vrací návrh a smyčku
   ukončí (human-in-the-loop).
3. Zápis jen přes FormRequest hosta + audit `origin=chatbot`.
4. Žádný raw SQL — jen typované filtry přes Eloquent, limit 50 řádků.
5. Tvrdý limit iterací smyčky.
6. Bezpečnostní preambule promptu je fixní v balíčku.
7. `user_ai_settings.api_key` je `encrypted`, do frontendu nikdy plaintext.

## Vývoj

```bash
composer install
composer test
```

Testy běží na Orchestra Testbench + Pest (in-memory SQLite).

## Licence

MIT.
