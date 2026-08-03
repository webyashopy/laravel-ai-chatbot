# webyashopy/laravel-ai-chatbot

Znovupoužitelný **AI chatbot (Anthropic Claude)** pro Laravel aplikace —
AI klient, usage logging, per-user API klíče a agentní tool-loop nad daty
host aplikace.

> **Status:** IMPLEMENTOVÁNO (TASK-090..101, 103). Extrakce z JirkaNaSteroidech (ADR-019
> hostující aplikace) je dokončená — AI klient, usage logging, per-user klíče, agentní
> tool-loop, modely konverzací, `ChatController` i console command `chatbot:models-check`
> jsou hotové a otestované (117 testů, Testbench + Pest).

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
# striktní režim per-user klíčů — viz sekce „Per-user API klíče" níže
CHATBOT_REQUIRE_USER_KEY=false
```

### 4) Vlastní autorizace (POVINNÉ pro aplikace s rolemi)

**Default balíčku (`AllowAuthenticatedChatAuthorizer`) pustí do chatu KOHOKOLI
přihlášeného** — a to včetně `canConfirmAction()`, tedy včetně **potvrzení
zápisu** (proposal → skutečný zápis do domény). Jinými slovy: bez vlastního
bindingu smí zápis potvrdit libovolný přihlášený uživatel, ne jen role k tomu
určené. Pro každou aplikaci s rolemi/oprávněními je proto vlastní binding
**povinný**, ne "nice to have" (nález bezpečnostního auditu TASK-099, LOW —
riziko samo o sobě nízké, protože zápis stále jde jen přes hostův FormRequest
a je auditovaný, ale princip least privilege to poruší).

Aplikace si ve vlastním provideru nabinduje vlastní implementaci:

```php
// app/Providers/ChatbotServiceProvider.php
use Webyashopy\Chatbot\Contracts\ChatAuthorizer;

public function register(): void
{
    $this->app->bind(ChatAuthorizer::class, PermissionChatAuthorizer::class);
}
```

```php
// app/Support/Chatbot/PermissionChatAuthorizer.php
use Webyashopy\Chatbot\Contracts\ChatAuthorizer;

class PermissionChatAuthorizer implements ChatAuthorizer
{
    public function canUseChat(mixed $user): bool
    {
        return $user?->can('chat.use') ?? false;
    }

    public function canConfirmAction(mixed $user, string $kind): bool
    {
        // Případně jemnější granularita per $kind (např. jiné oprávnění
        // pro potvrzení faktury než objednávky).
        return $user?->can('chat.use') ?? false;
    }
}
```

Binding je přes `bind()` (ne `singleton()`) a aplikační provider bootuje až
po balíčkovém, takže vlastní implementace vždy vyhraje nad defaultem
(`bindIf()` v balíčku).

## Konfigurace (`config/chatbot.php`)

| Klíč | Default | Popis |
|---|---|---|
| `user_model` | `App\Models\User` | Model uživatele hosta (env `CHATBOT_USER_MODEL`) |
| `features.chat` | `true` | `false` = jen AI vrstva, bez chat rout a tool-loopu |
| `features.documents` | `true` | `false` = vypne digitalizaci dokumentů (env `CHATBOT_FEATURE_DOCUMENTS`) |
| `encrypt_messages` | `false` | Šifrování `content`/`action`/`steps` (`encrypted` casty) — viz sekce „Šifrování zpráv" níže (env `CHATBOT_ENCRYPT_MESSAGES`) |
| `routes.prefix` | `chat` | Prefix rout balíčku (env `CHATBOT_ROUTE_PREFIX`) |
| `routes.middleware` | `['web','auth']` | Middleware rout; host přidá své (`can:chat.use`) |
| `routes.as` | `chat.` | Prefix názvů rout — **neměnit** (stojí na tom frontend) |
| `api.key` / `api.url` / `api.version` | env | Anthropic API (env `ANTHROPIC_*`) |
| `api.require_user_key` | `false` | Striktní režim: uživatel bez vlastního klíče chat nepoužije (env `CHATBOT_REQUIRE_USER_KEY`) |
| `default_model` | `claude-sonnet-4-5-20250929` | Model nové konverzace (env `CHATBOT_MODEL`) |
| `model` | `claude-sonnet-4-5-20250929` | Model pro jednorázové `complete()` |
| `models` | 3 modely | Allowlist modelů pro chat; mimo něj → 422 |
| `pricing` | viz config | CZK / 1 Mtok (`input`/`output`) pro výpočet `cost`. **Models API cenu NEVRACÍ** — ceník je vždy ruční, aktualizuj dle ceníku Anthropic a kurzu CZK/USD (viz `chatbot:models-check` níže) |
| `retry` | 3 / 1000 ms / ×2 | Retry a exponenciální backoff volání API |
| `timeouts` | 60 / 10 s | HTTP `request` / `connect` timeout |
| `rate.per_purpose` | `chat: 20`, `ocr: 10`, `document: 10` | Limit volání/min na uživatele dle účelu |
| `rate.default` | `10` | Fallback pro neznámý účel |
| `chat.history_limit` | `20` | Kolik posledních zpráv jde do promptu |
| `chat.tools.enabled` | `true` | Kill-switch nástrojů |
| `chat.tools.max_iterations` | `5` | Tvrdý limit iterací agentní smyčky |
| `chat.tools.capable_models` | 3 modely | Modely umějící tool-use; jinak fallback na text |
| `tools.discover_paths` | `app/Services/Ai/Tools` | Kde se hledají `ChatTool` hosta |
| `actions.discover_paths` | `app/Services/Ai/Actions` | Kde se hledají `ChatActionHandler` hosta |
| `documents.*` | viz níže | Digitalizace dokumentů — model, limity, disk, discovery schémat |
| `prompts.context` | `''` | Doménový kontext hosta (preambule je fixní v balíčku) |

Config **neobsahuje closures** — je cacheovatelný přes `config:cache`.

## Per-user API klíče a nastavení

Každý uživatel si může uložit **vlastní Anthropic API klíč** — v DB leží
zašifrovaný (`user_ai_settings.api_key`, `encrypted` cast) a do frontendu se
nikdy neposílá (props nesou jen `has_api_key: bool`).

Resolution klíče při volání API: **klíč uživatele → fallback na serverový
`ANTHROPIC_API_KEY`**. Se zapnutým `api.require_user_key` fallback pro
uživatelská volání odpadá — kdo nemá vlastní klíč, chat nepoužije
(`store`/`message` vrátí 302 + `errors.api_key`; `AiService` vyhodí
`Exceptions\MissingUserApiKeyException`). Volání **bez** usera (systémová,
např. `chatbot:models-check`) jedou na serverový klíč vždy.

### Routy nastavení

| Metoda | URI | Název | Popis |
|---|---|---|---|
| GET | `chat/nastaveni` | `chat.settings.show` | Stav nastavení (`has_api_key`, `preferred_model`, `require_user_key`) |
| PUT | `chat/nastaveni` | `chat.settings.update` | Uložení/přepsání klíče (`api_key`, min. 20 znaků) |
| DELETE | `chat/nastaveni/klic` | `chat.settings.key.destroy` | Odstranění klíče (záznam s `preferred_model` zůstává) |

Routy jedou přes touž bránu `ChatAuthorizer::canUseChat()` a middleware
z `chatbot.routes.middleware` jako zbytek chatu; controller přepíšeš IoC
bindingem (`ChatSettingsController`, array syntax rout).

### Integrace v hostovi

Balíček renderuje Inertia stránku **`chat/settings`** — komponentu dodává
host (stejný vzor jako `chat/index`), např.
`resources/js/pages/chat/settings.tsx` s props:

```ts
interface ChatSettingsProps {
    has_api_key: boolean;          // klíč samotný se do FE nikdy neposílá
    preferred_model: string | null;
    require_user_key: boolean;     // true → bez klíče chat nejede, FE to vysvětlí
}
```

Formulář posílá `PUT chat/nastaveni` s polem `api_key`, smazání
`DELETE chat/nastaveni/klic`. Chybu chybějícího klíče při psaní do chatu
najde FE v `errors.api_key` (store i message).

## Kontrakty

| Kontrakt | Účel | Default balíčku |
|---|---|---|
| `Contracts\ChatAuthorizer` | Kdo smí chat / potvrdit akci | `Support\AllowAuthenticatedChatAuthorizer` |
| `Contracts\ChatTool` | Nástroj nad daty hosta | — (dodává host) |
| `Contracts\ChatActionHandler` | Potvrzení navrženého zápisu | — (dodává host) |
| `Contracts\DocumentSchema` | Co vytáhnout z dokumentu | — (dodává host; předek `Support\BaseDocumentSchema`) |

Bindingy jsou přes `bind()`, ne `singleton()` — host je snadno přepíše.

### Registrace nástrojů a handlerů

Primární cesta je **self-discovery**: nový nástroj = **jeden nový soubor**
v některém z adresářů `tools.discover_paths`. Žádný registr, seznam v configu
ani jiný sdílený soubor se needituje — dva vývojáři tak přidávají nástroje
souběžně bez kolize v gitu (ADR-019 §6; ruční seznam byl zamítnut).

```php
// app/Services/Ai/Tools/ReadFakturyTool.php  — víc není potřeba
class ReadFakturyTool implements \Webyashopy\Chatbot\Contracts\ChatTool { … }
```

Sken je rekurzivní (podadresáře `Tools/Read/`, `Tools/Write/` fungují),
namespace se odvozuje z **PSR-4 mapy composeru** (nemusí to být `App\`),
běží **jen nad adresáři hosta** a cesty uvnitř `vendor/` přeskakuje.
Stejný mechanismus platí pro `ChatActionHandler` a `actions.discover_paths`.

Pro třídy mimo prohledávané cesty je explicitní API:

```php
use Webyashopy\Chatbot\Chatbot;

Chatbot::registerTool(ReadFakturyTool::class);
Chatbot::registerActionHandler(PartnerActionHandler::class);
Chatbot::registerDocumentSchema(FakturaSchema::class);
```

Kill-switch `chat.tools.enabled = false` → registr nástrojů je prázdný
(modelu se nepošle žádný nástroj). Na `ChatActionHandlerRegistry` se
záměrně nevztahuje — rozpracovaný návrh musí jít potvrdit i potom.

### Jak napsat vlastní `ChatTool`

```php
namespace App\Services\Ai\Tools\Read;

use Webyashopy\Chatbot\Contracts\ChatTool;

class ReadFakturyTool implements ChatTool
{
    public function name(): string
    {
        return 'read_faktury';
    }

    public function definition(): array
    {
        // Anthropic tool schema (name/description/input_schema).
        return ['name' => $this->name(), 'description' => '…', 'input_schema' => [...]];
    }

    public function handle(array $input, mixed $user): array
    {
        // 1) RE-AUTORIZUJ pod $user — nikdy nespoléhej, že o autorizaci
        //    rozhodl jen ChatAuthorizer (defense-in-depth, ADR-017 §5).
        // 2) Typované filtry přes Eloquent (žádný raw SQL).
        // 3) Tvrdý limit řádků (doporučeno ≤50) — model nemá dostat celou tabulku.
        return ['faktury' => [...]];
    }
}
```

Read nástroje vždy vrací data. **Write nástroje NIC nezapisují** — vrátí
`['status' => 'proposal', 'kind' => '…', 'payload' => [...], 'summary' => '…']`
a smyčku tím okamžitě ukončí (human-in-the-loop, ADR-017 §4).

### Jak napsat vlastní `ChatActionHandler`

Potvrzení proposalu (`ChatActionHandler::confirm()`) je jediné místo, kde
smí dojít ke skutečnému zápisu domény. **Kontrakt to technicky NEVYNUCUJE** —
handler by mohl zapsat cokoliv čímkoliv, disciplína je na implementaci hosta:

```php
namespace App\Services\Ai\Actions;

use Webyashopy\Chatbot\Contracts\ChatActionHandler;
use Webyashopy\Chatbot\Support\ChatActionResult;

class CustomerOrderActionHandler implements ChatActionHandler
{
    public function kind(): string
    {
        return 'customer_order';
    }

    public function confirm(array $payload, mixed $user, array $context = []): ChatActionResult
    {
        // SPRÁVNĚ: validuj payload přes existující FormRequest hosta (payload
        // pochází od modelu, tedy z NEOVĚŘENÉHO zdroje) a zapiš přes stávající
        // aplikační cestu (např. Controller::createFromValidated()) — NE
        // Model::create() přímo z handleru. Payload od modelu nikdy nesmí
        // obejít validaci, kterou prochází ruční formulář.
        $validated = app(StoreCustomerOrderRequest::class)->validateResolved($payload);
        $order = app(CustomerOrderController::class)->createFromValidated($validated, $user);

        // Audit s origin=chatbot + $context (conversation_id/chat_message_id,
        // ADR-004) — auditní stopa musí vědět, ŽE i ODKUD zápis vzešel.
        AuditLog::record('customer_order.create', $order, context: [
            'origin' => 'chatbot',
            ...$context,
        ]);

        return ChatActionResult::success(
            message: 'Objednávka byla založena.',
            resultId: $order->id,
            redirectRoute: 'orders.show',
            redirectParams: ['order' => $order->id],
        );
    }
}
```

Neplatný payload → `ChatActionResult::failure($message, $errors)`, controller
z toho udělá **302 + `session('errors')`** (standardní Laravel FormRequest
chování), ne holé 422 JSON.

### Systémový prompt

`Support\SystemPrompt` skládá prompt ze **fixní preambule balíčku**
(role, práce s nástroji, zásada human-in-the-loop) a **doménového kontextu
hosta** z `prompts.context`. Host preambuli nemůže přepsat ani vypnout —
prompt je bezpečnostní prvek (ADR-019 §7) a zásada potvrzování zápisu
z něj nesmí zmizet. Kontext se připojuje až za preambuli a uzavírá ho fixní
zápatí balíčku, které jeho podřízenost pravidlům výslovně kotví — výsledné
pořadí je `preambule → kontext hosta → zápatí`. Poslední slovo tak má vždy
balíček, ne host.

`prompts.context` snese i pole (seznam odstavců se spojí); hodnota jiného
typu se ignoruje, prompt se kvůli configu hosta nikdy nerozbije.

```php
'prompts' => ['context' => 'Jsi asistent v logistickém systému Alewerans Logistics. …'],
```

## Digitalizace dokumentů

Extrakce strukturovaných dat z PDF a obrázků — typicky pro **předvyplnění
formuláře** z nahrané faktury, dodacího listu nebo dokladu. Balíček dodává
vrstvu, host popisuje **co** se má z dokumentu vytáhnout.

Vypíná se feature flagem `chatbot.features.documents`
(env `CHATBOT_FEATURE_DOCUMENTS=false`) — registr schémat pak vrací prázdno.

### 1) Napiš schéma

Schéma je třída implementující `Contracts\DocumentSchema`; nejpohodlnější je
podědit `Support\BaseDocumentSchema` a doplnit jen tři metody. Uloží se do
`app/Services/Ai/Documents/` (cesta z `chatbot.documents.schemas.discover_paths`)
a **nikde se neregistruje** — najde ji self-discovery.

```php
namespace App\Services\Ai\Documents;

use Webyashopy\Chatbot\Support\BaseDocumentSchema;

final class FakturaSchema extends BaseDocumentSchema
{
    public function name(): string
    {
        return 'faktura';
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
                'cislo' => ['type' => 'string', 'description' => 'Číslo faktury'],
                'ico_dodavatele' => ['type' => ['string', 'null'], 'description' => 'IČO, 8 číslic'],
                'datum_splatnosti' => ['type' => ['string', 'null'], 'format' => 'date'],
                'castka_bez_dph' => ['type' => 'number'],
            ],
        ];
    }

    public function instructions(): string
    {
        return 'Částky vracej jako číslo bez měny. Datumy ve formátu RRRR-MM-DD.';
    }

    // Volitelně: postprocessing (normalizace IČO, dopočet DPH…).
    public function transform(array $data): array
    {
        return $data;
    }
}
```

**Omezení JSON Schema** (Anthropic structured outputs): nepodporuje rekurzivní
schémata, `minimum`/`maximum`, `minLength`/`maxLength` ani `additionalProperties`
s jinou hodnotou než `false`. Rozsahy validuj v `transform()` nebo ve validaci
hosta, ne ve schématu.

**Nepovinný údaj** se zapisuje jako **nullable typ** (`['string', 'null']`), ne
vynecháním z `required`. Balíček doplní `additionalProperties: false` všem
objektům a — když `required` neuvedeš vůbec — vyplní ho všemi vlastnostmi.

### 2) Zavolej digitalizaci

```php
use Webyashopy\Chatbot\Facades\Documents;

$result = Documents::digitize($request->file('soubor'), 'faktura', $request->user());

return back()->with('predvyplneno', $result->data());
// ['cislo' => 'FV2026001', 'ico_dodavatele' => '12345678',
//  'datum_splatnosti' => '2026-08-15', 'castka_bez_dph' => 12500.0]
```

Když chceš krok uložení a extrakce oddělit (např. nahrát teď, vytěžit ve frontě):

```php
$document = Documents::store($request->file('soubor'), $request->user());
$result   = Documents::extract($document, 'faktura', $request->user());
```

| Metoda | Co dělá |
|---|---|
| `store($file, $user)` | Ověří a uloží soubor, vrátí `ChatDocument` |
| `extract($document, $schema, $user, $force, $options)` | Vytěží data, vrátí `ExtractionResult` |
| `digitize($file, $schema, $user, …)` | `store()` + `extract()` v jednom |
| `delete($document)` | Smaže záznam i soubor (pro retenční úlohy) |
| `schemas()` | Registr schémat — `options()` dá mapu pro select v UI |

`ExtractionResult` nabízí `data()`, `get('klic.0.podklic')`, `model()`, `usage()`,
`cost()`, `wasCached()` a `extractionId()`.

### Náklady a znovupoužití

Poslední **úspěšná** extrakce dvojice (dokument, schéma) se vrací z DB **bez
volání API** — opakované otevření formuláře nad stejnou fakturou tedy nestojí
nic (`$result->wasCached() === true`). Nové volání si vynutíš `force: true`,
typicky po opravě schématu:

```php
$result = Documents::extract($document, 'faktura', $user, force: true);
```

Stejný soubor od téhož uživatele se neukládá dvakrát — deduplikace jde přes
SHA-256 obsahu, takže druhá nahrávka rovnou zdědí hotové extrakce.

Neúspěšné pokusy se ukládají také (`status = 'failed'` + `error`): volání
proběhlo a zaplatilo se, takže po něm musí zůstat stopa.

### Model a limity

| Klíč | Default | Poznámka |
|---|---|---|
| `documents.model` | `claude-sonnet-5` | Samostatný env `CHATBOT_DOCUMENT_MODEL`. Sonnet 5 má 1M kontext a zvládne PDF do 600 stran; haiku jen 200k a 100 stran |
| `documents.max_tokens` | `8192` | Dlouhý ceník se do menšího stropu nevejde a JSON se usekne |
| `documents.max_size_mb` | `20` | Anthropic má strop 32 MB na request a base64 obsah nafoukne ~1,37× |
| `documents.max_pages` | `200` | Brzda nákladů, ne technický strop |
| `documents.disk` / `.path` | `local` / `chatbot/documents` | **Musí být privátní disk** — doklady nepatří pod `public` |
| `rate.per_purpose.document` | `10`/min | Vlastní bucket, odděleně od chatu |

### Chybové stavy

| Výjimka | Kdy |
|---|---|
| `UnsupportedDocumentException` | Typ souboru mimo `allowed_mime` (MIME se čte z **obsahu**, ne z přípony), nečitelný soubor, poškozené PDF |
| `DocumentTooLargeException` | Přes limit velikosti nebo počtu stran |
| `UnknownDocumentSchemaException` | Schéma není registrované, nebo je vypnutá feature |
| `ExtractionFailedException` | Odpověď nejde použít — neplatný JSON, useknuto limitem `max_tokens`, odmítnuto modelem |

### Co balíček záměrně nedělá

- **Citace** (odkaz „tato částka je na straně 3") — Anthropic je nekombinuje
  se strukturovaným výstupem (vrací 400) a vynucené schéma je pro vyplňování
  formulářů podstatnější.
- **Mapování na doménové modely** — `ExtractionResult::data()` je obyčejné pole,
  co s ním, si řídí host (ADR-019 §6).
- **UI** — upload formulář a náhled dat patří do hosta, stejně jako u chatu.
## Šifrování zpráv (TASK-AIBOT-01g)

Chat může nést zvláštní kategorie osobních údajů (GDPR čl. 9) — pokud host
skládá odpovědi nástrojů z citlivých doménových dat (např. zdravotní údaje),
**před produkčním nasazením zapni `chatbot.encrypt_messages`** (env
`CHATBOT_ENCRYPT_MESSAGES=true`, default `false`).

Zapnutí přepne `Models\ChatMessage::casts()`:

- `content` → `encrypted`,
- `action` → `encrypted:array` (payload write nástroje může nést PII),
- `steps` → `encrypted:array` (průběh tool-use smyčky).

Precedent v balíčku: `Models\UserAiSettings` `api_key => encrypted`.

**`chat_conversations.title` se při zapnutém přepínači NEPLNÍ z textu
uživatele** — `ChatController::initialTitle()` vrátí generický titulek
(„Konverzace 3.8.2026") místo `Str::limit($message, 60)`. Sloupec `title`
zůstává i po zapnutí plaintext (šifrování by kvůli délce ciphertextu
přetéklo `varchar(255)`) — generický titulek je jediná ochrana proti leaku.

### Dopady zapnutí

- **DB fulltext / `LIKE` nad `content`/`action`/`steps` přestane fungovat.**
  Balíček sám nad těmito sloupci nevyhledává; pokud to dělá host, musí to
  sám ošetřit (jiný zdroj, samostatný nešifrovaný index) nebo si nechat
  přepínač vypnutý.
- **`APP_KEY` hosta musí zůstat stabilní.** Laravel `encrypted` cast
  dešifruje jen proti aktuálnímu klíči — rotace `APP_KEY` bez re-encryptu
  znamená ztrátu čitelnosti CELÉ historie zpráv.
- **Existující data se nepřeshiftrují automaticky.** Přepnutí platí jen pro
  nově uložené zprávy; re-encrypt/backfill staré historie (byla-li nějaká
  uložena s vypnutým přepínačem) je na hostovi.

### Migrace `action`/`steps` json → text

Sloupce `chat_messages.action`/`steps` jsou v čisté instalaci `json`
(nativní typ PostgreSQL) — ale `encrypted:array` ukládá base64 ciphertext
string, který PostgreSQL do `json` sloupce nepřijme (musí to být validní
JSON hodnota). Migrace balíčku
(`database/migrations/2026_08_03_100000_change_chat_messages_action_steps_to_text.php`)
je mění na `text` — `array`/`encrypted:array` cast funguje nad `text`
sloupcem beze změny.

Migrace je no-op na SQLite (tam `json` odjakživa kompiluje na `text`
afinitu) a záměrně no-op na MySQL (mimo podporovanou produkční matici
balíčku — host na MySQL by potřeboval vlastní migraci).

## Bezpečnostní invarianty

1. Každý tool handler se re-autorizuje pod přihlášeným uživatelem.
2. Žádný zápis do domény z nástroje — write nástroj vrací návrh a smyčku
   ukončí (human-in-the-loop).
3. Zápis jen přes FormRequest hosta + audit `origin=chatbot`.
4. Žádný raw SQL — jen typované filtry přes Eloquent, limit 50 řádků.
5. Tvrdý limit iterací smyčky.
6. Bezpečnostní preambule promptu je fixní v balíčku.
7. `user_ai_settings.api_key` je `encrypted`, do frontendu nikdy plaintext —
   nastavení posílá jen `has_api_key: bool` a klíč jde vždy jen směrem dovnitř.
8. Se zapnutým `api.require_user_key` se uživatel bez vlastního klíče odmítne
   PŘED voláním API (žádný tichý fallback na serverový klíč).
9. Typ nahraného dokumentu se určuje z **obsahu** souboru (finfo), nikdy
   z přípony ani z hlavičky od klienta.
10. Text uvnitř dokumentu je pro model vždy **data**, nikdy instrukce —
    systémový prompt extrakce je fixní v balíčku a obsahuje ochranu proti
    prompt injection (naskenovaná faktura může obsahovat „ignoruj předchozí
    pokyny").
11. Překročení limitu velikosti nebo počtu stran je **výjimka, ne tiché
    oříznutí** — oříznutá faktura by se extrahovala „úspěšně" a chybějící
    položky by nikdo nepoznal.
12. Se zapnutým `encrypt_messages` jsou `content`/`action`/`steps`
    `chat_messages` šifrované (`encrypted` casty) a titulek konverzace se
    neplní z textu uživatele — viz sekce „Šifrování zpráv" výše.

## Console command `chatbot:models-check`

```bash
php artisan chatbot:models-check
```

Zavolá Anthropic Models API (`GET {chatbot.api.url}/models`) a porovná
`chatbot.models` + `chatbot.chat.tools.capable_models` proti reálně
dostupným modelům. VAROVÁNÍM (ne chybou) nahlásí:

- model, který z API zmizel (retired nebo přejmenovaný),
- model uvedený jako tool-capable (`chat.tools.capable_models`), který
  chybí v allowlistu `chatbot.models`.

**Nic nepřepíná automaticky.** Floating alias typu „nejnovější Opus"
v Anthropic API neexistuje (modely mají fixní id) a auto-upgrade mezi
generacemi je breaking change — modely řady Opus 4.7+ odmítají
`temperature`/`top_p`/`top_k` i `budget_tokens` (400 Bad Request), takže
tichá záměna modelu v configu by mohla shodit produkční provoz. Rozhodnutí,
co s nahlášeným modelem udělat, je vždy na člověku.

Naplánován **týdně** (`ChatbotServiceProvider` přes
`callAfterResolving(Schedule::class, ...)`, po vzoru `tickets:notify-stale`
z `webyashopy/laravel-ticketing-system`) — scheduling se automaticky
přeskočí, když `chatbot.api.key` (env `ANTHROPIC_API_KEY`) není nastavený,
aby cron zbytečně nevolal příkaz, který se stejně hned přeskočí.

**Explicitní poznámka:** Models API **nevrací cenu modelu** (`pricing`) —
`config('chatbot.pricing')` proto zůstává výhradně ruční a `chatbot:models-check`
ho nekontroluje. `ai_usage_logs.cost` je tak přesný, jak často host aktualizuje
ceník dle skutečného ceníku Anthropic.

## Vývoj

```bash
composer install
composer test
```

Testy běží na Orchestra Testbench + Pest (in-memory SQLite).

## Licence

MIT.
