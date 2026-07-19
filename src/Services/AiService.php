<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use Webyashopy\Chatbot\Exceptions\MissingUserApiKeyException;
use Webyashopy\Chatbot\Models\AiUsageLog;
use Webyashopy\Chatbot\Models\UserAiSettings;
use Webyashopy\Chatbot\Support\Purpose;

/**
 * Klient pro Anthropic Claude API (ADR-010/ADR-015, extrahováno z JNS
 * v rámci ADR-019/TASK-091).
 *
 * Poskytuje textové i multimodální (vision) volání s retry/backoff a
 * jednoduchým per-minutovým rate limiterem přes cache (žádný Redis
 * token-bucket — pro objem requestů stačí cache default).
 *
 * Balíček NIKDY nečte config host aplikace (riziko K2) — veškeré nastavení
 * jde přes `config('chatbot.*')`:
 * `chatbot.model` (default model), `chatbot.api.*` (klíč/URL/verze),
 * `chatbot.retry.*`, `chatbot.timeouts.*`, `chatbot.rate.*`, `chatbot.pricing`.
 *
 * Volitelný kontext v `$options` (ADR-015): `user` (kdo dotaz vyvolal —
 * ovlivňuje resolution klíče a log; typ `mixed`, host model se neimportuje),
 * `purpose` (volný string dle ADR-019 §3, default {@see Purpose::CHAT}),
 * `model` (override, default `config('chatbot.model')`), `conversation_id`.
 * Po KAŽDÉM volání (i chybě) se zapisuje `ai_usage_logs`.
 */
class AiService
{
    protected string $model;

    public function __construct()
    {
        $this->model = (string) config('chatbot.model');
    }

    /**
     * Provede dokončení promptu, volitelně s obrázky (multimodal vision).
     *
     * @param  string|array<int,array<string,mixed>>|null  $systemPrompt
     * @param  array<string,mixed>  $options  Podporuje klíč `images` (base64 PNG/JPEG stránky,
     *                                        vision vstup; prázdné/chybějící = text-only) a kontext
     *                                        pro usage log: `user` (mixed), `purpose` (string,
     *                                        default `chat`), `model` (override, default
     *                                        `config('chatbot.model')`), `conversation_id` (?int).
     * @return array{content:string, usage:array<string,mixed>, model:string, stop_reason:?string}
     *
     * @throws \RuntimeException Rate limit vyčerpán, API klíč chybí, nebo API selhalo po retry.
     */
    public function complete(string $prompt, string|array|null $systemPrompt = null, array $options = []): array
    {
        $images = $options['images'] ?? [];
        unset($options['images']);

        $user = $options['user'] ?? null;
        $purpose = (string) ($options['purpose'] ?? Purpose::CHAT);
        $model = $options['model'] ?? $this->model;
        $conversationId = $options['conversation_id'] ?? null;
        unset($options['user'], $options['purpose'], $options['model'], $options['conversation_id']);

        [$apiKey, $keySource] = $this->resolveApiKey($user);

        try {
            $this->checkRateLimit($purpose, $user);

            $messages = $this->buildMessages($prompt, is_array($images) ? $images : []);
            $response = $this->sendRequest($messages, $systemPrompt, $options, $apiKey, $model);
        } catch (Throwable $e) {
            $this->logUsage($user, $model, $purpose, [], $keySource, false, $e->getMessage(), $conversationId);

            throw $e;
        }

        $this->logUsage($user, $model, $purpose, $response['usage'] ?? [], $keySource, true, null, $conversationId);

        return $response;
    }

    /**
     * Multi-turn volání Anthropic API s podporou `tools` (ADR-017) — na rozdíl
     * od {@see complete()} pracuje nad polem zpráv a vrací PLNÉ content bloky
     * (text i `tool_use`), nezahazuje strukturu. Díky tomu může volající
     * (agentní smyčka) provést tool-use smyčku (tool_use → provést nástroj →
     * tool_result → další `converse()`).
     *
     * @param  array<int, array<string, mixed>>  $messages  Historie konverzace (multi-turn).
     * @param  string|array<int, array<string, mixed>>|null  $system
     * @param  array<string, mixed>  $options  Kontext pro usage log: `user` (mixed), `purpose`
     *                                         (string, default `chat`), `model` (override),
     *                                         `conversation_id` (?int), a `tools` (Anthropic tool
     *                                         schema pole — prázdné/chybějící = bez tools v těle),
     *                                         `max_tokens`.
     * @return array{content:array<int,array<string,mixed>>, usage:array<string,mixed>, model:string, stop_reason:?string}
     *
     * @throws \RuntimeException Rate limit vyčerpán, API klíč chybí, nebo API selhalo po retry.
     */
    public function converse(array $messages, string|array|null $system = null, array $options = []): array
    {
        $user = $options['user'] ?? null;
        $purpose = (string) ($options['purpose'] ?? Purpose::CHAT);
        $model = $options['model'] ?? $this->model;
        $conversationId = $options['conversation_id'] ?? null;
        unset($options['user'], $options['purpose'], $options['model'], $options['conversation_id']);

        [$apiKey, $keySource] = $this->resolveApiKey($user);

        try {
            $this->checkRateLimit($purpose, $user);

            $response = $this->sendConverseRequest($messages, $system, $options, $apiKey, $model);
        } catch (Throwable $e) {
            $this->logUsage($user, $model, $purpose, [], $keySource, false, $e->getMessage(), $conversationId);

            throw $e;
        }

        // Loguje se KAŽDÝ round-trip (ADR-015) — volající volá converse()
        // opakovaně ve smyčce, každé volání je samostatný log řádek.
        $this->logUsage($user, $model, $purpose, $response['usage'] ?? [], $keySource, true, null, $conversationId);

        return $response;
    }

    /**
     * Je zapnutý striktní režim per-user klíčů (`chatbot.api.require_user_key`)?
     * Veřejné — controller i host si režim zjistí bez sahání na config balíčku.
     */
    public function requiresUserKey(): bool
    {
        return (bool) config('chatbot.api.require_user_key', false);
    }

    /**
     * Má uživatel vlastní API klíč v `user_ai_settings`? Veřejné — controller
     * (i host) přes něj uživatele bez klíče odmítne SROZUMITELNĚ ještě před
     * voláním API, místo aby výjimka zapadla v obecném catch bloku.
     */
    public function userHasApiKey(mixed $user): bool
    {
        $userId = $this->userId($user);

        if ($userId === null) {
            return false;
        }

        $key = UserAiSettings::query()->where('user_id', $userId)->first()?->api_key;

        return $key !== null && $key !== '';
    }

    /**
     * Resolution API klíče (ADR-015): per-user klíč z `user_ai_settings`
     * (dešifrovaný `encrypted` cast) → fallback na serverový klíč z configu
     * balíčku (`chatbot.api.key`, env `ANTHROPIC_API_KEY`).
     *
     * Striktní režim (`chatbot.api.require_user_key`): volání S uživatelem bez
     * vlastního klíče se odmítne {@see MissingUserApiKeyException} — env klíč
     * pak slouží jen voláním bez usera (systémová, např. `chatbot:models-check`).
     * Výjimka letí PŘED rate limitem i logováním: žádné volání neproběhlo,
     * nespotřebuje se pokus z bucketu ani nevznikne řádek v `ai_usage_logs`.
     *
     * Nastavení se hledá dotazem přes `user_id`, ne přes relaci na host User
     * modelu — balíček nesmí předpokládat, že host má relaci `aiSettings`.
     *
     * @return array{0:string, 1:string} [klíč, zdroj klíče ('user'|'env')]
     *
     * @throws MissingUserApiKeyException Striktní režim a uživatel nemá vlastní klíč.
     */
    protected function resolveApiKey(mixed $user): array
    {
        $userId = $this->userId($user);

        $userApiKey = $userId === null
            ? null
            : UserAiSettings::query()->where('user_id', $userId)->first()?->api_key;

        if ($userApiKey !== null && $userApiKey !== '') {
            return [$userApiKey, 'user'];
        }

        if ($userId !== null && $this->requiresUserKey()) {
            throw new MissingUserApiKeyException;
        }

        return [(string) config('chatbot.api.key'), 'env'];
    }

    /**
     * Identifikátor uživatele bez vazby na host User model (`mixed`).
     */
    protected function userId(mixed $user): int|string|null
    {
        if ($user === null) {
            return null;
        }

        if ($user instanceof Model) {
            /** @var int|string|null $key */
            $key = $user->getKey();

            return $key;
        }

        return $user->id ?? null;
    }

    /**
     * Zapíše `ai_usage_logs` (ADR-015) — append-only, po každém volání (i chybě).
     *
     * @param  array<string,mixed>  $usage
     */
    protected function logUsage(
        mixed $user,
        string $model,
        string $purpose,
        array $usage,
        string $keySource,
        bool $success,
        ?string $error,
        ?int $conversationId,
    ): void {
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        AiUsageLog::create([
            'user_id' => $this->userId($user),
            'model' => $model,
            'purpose' => $purpose,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $this->calculateCost($model, $inputTokens, $outputTokens),
            'key_source' => $keySource,
            'success' => $success,
            'error' => $error,
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * Spočítá cenu volání v CZK dle `config('chatbot.pricing')`. Neznámý model →
     * `null` + varování do logu (ne pád), viz ADR-015 §4.
     */
    protected function calculateCost(string $model, int $inputTokens, int $outputTokens): ?float
    {
        $pricing = config("chatbot.pricing.{$model}");

        if (! is_array($pricing) || ! isset($pricing['input'], $pricing['output'])) {
            Log::warning('AiService: neznámý model, cena nákladů nebude spočtena (cost=null)', [
                'model' => $model,
            ]);

            return null;
        }

        return ($inputTokens / 1_000_000 * (float) $pricing['input'])
            + ($outputTokens / 1_000_000 * (float) $pricing['output']);
    }

    /**
     * Sestaví `messages` pole pro Anthropic API — text-only nebo multimodal.
     *
     * Multimodal: image bloky NEJDŘÍV, pak text (Anthropic best practice).
     *
     * @param  array<int,string>  $images
     * @return array<int,array<string,mixed>>
     */
    protected function buildMessages(string $prompt, array $images = []): array
    {
        if ($images === []) {
            return [['role' => 'user', 'content' => $prompt]];
        }

        $content = [];
        foreach ($images as $b64) {
            $content[] = [
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => $this->detectImageMediaType($b64), 'data' => $b64],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        return [['role' => 'user', 'content' => $content]];
    }

    /**
     * Detekuje MIME obrázku z magic bytes base64 stringu (PNG/JPEG).
     */
    protected function detectImageMediaType(string $b64): string
    {
        $header = base64_decode(substr($b64, 0, 16), true);
        if ($header === false || $header === '') {
            return 'image/png';
        }

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        return 'image/png';
    }

    /**
     * Odešle požadavek na Claude API s retry/backoff na 429/5xx.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  string|array<int,array<string,mixed>>|null  $systemPrompt
     * @param  array<string,mixed>  $options
     * @return array{content:string, usage:array<string,mixed>, model:string, stop_reason:?string}
     */
    protected function sendRequest(
        array $messages,
        string|array|null $systemPrompt,
        array $options,
        string $apiKey,
        string $model,
    ): array {
        if ($apiKey === '') {
            throw new \RuntimeException('Anthropic API klíč není nastaven (ANTHROPIC_API_KEY).');
        }

        $body = $this->buildBody($messages, $systemPrompt, $options, $model);
        $headers = $this->buildHeaders($apiKey);
        $data = $this->callAnthropic($body, $headers);

        $content = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $content .= $block['text'];
            }
        }

        return [
            'content' => $content,
            'usage' => $data['usage'] ?? [],
            'model' => $model,
            'stop_reason' => $data['stop_reason'] ?? null,
        ];
    }

    /**
     * Obdoba {@see sendRequest()} pro {@see converse()} — NEzahazuje content
     * bloky do textu, vrací je tak, jak přišly z API (text i tool_use).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  string|array<int, array<string, mixed>>|null  $systemPrompt
     * @param  array<string, mixed>  $options
     * @return array{content:array<int,array<string,mixed>>, usage:array<string,mixed>, model:string, stop_reason:?string}
     */
    protected function sendConverseRequest(
        array $messages,
        string|array|null $systemPrompt,
        array $options,
        string $apiKey,
        string $model,
    ): array {
        if ($apiKey === '') {
            throw new \RuntimeException('Anthropic API klíč není nastaven (ANTHROPIC_API_KEY).');
        }

        $body = $this->buildBody($messages, $systemPrompt, $options, $model);
        $headers = $this->buildHeaders($apiKey);
        $data = $this->callAnthropic($body, $headers);

        return [
            'content' => $data['content'] ?? [],
            'usage' => $data['usage'] ?? [],
            'model' => $model,
            'stop_reason' => $data['stop_reason'] ?? null,
        ];
    }

    /**
     * Odešle požadavek na Claude API s retry/backoffem na 429/5xx — sdíleno
     * {@see sendRequest()} (complete()) a {@see sendConverseRequest()} (converse()).
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed> Dekódovaná JSON odpověď Anthropic API.
     *
     * @throws \RuntimeException API selhalo po všech pokusech, nebo nenávratná chyba.
     */
    protected function callAnthropic(array $body, array $headers): array
    {
        $attempt = 0;
        $maxAttempts = (int) config('chatbot.retry.max_attempts', 3);
        $delay = (int) config('chatbot.retry.delay_ms', 1000);
        $multiplier = (int) config('chatbot.retry.multiplier', 2);

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $response = Http::timeout((int) config('chatbot.timeouts.request', 60))
                    ->connectTimeout((int) config('chatbot.timeouts.connect', 10))
                    ->withHeaders($headers)
                    ->post(rtrim((string) config('chatbot.api.url'), '/').'/messages', $body);

                if ($response->successful()) {
                    return $response->json();
                }

                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? 'Neznámá chyba';
                $errorType = $errorData['error']['type'] ?? 'unknown';

                Log::error('AiService: API chyba', [
                    'status' => $response->status(),
                    'error_type' => $errorType,
                ]);

                // Chyby, které nemá smysl opakovat.
                if (in_array($errorType, ['authentication_error', 'invalid_request_error', 'not_found_error'], true)) {
                    throw new \RuntimeException("Claude API chyba ({$response->status()}): {$errorMessage}");
                }

                // Rate limit / přetížení — retry s backoffem.
                if ($response->status() === 429 || $response->status() >= 500) {
                    if ($attempt >= $maxAttempts) {
                        throw new \RuntimeException("Claude API nedostupné po {$maxAttempts} pokusech: {$errorMessage}");
                    }

                    usleep($delay * 1000);
                    $delay *= $multiplier;

                    continue;
                }

                throw new \RuntimeException("Claude API chyba ({$response->status()}): {$errorMessage}");
            } catch (RequestException $e) {
                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException('Claude API nedostupné po '.$maxAttempts.' pokusech: '.$e->getMessage());
                }

                Log::warning("AiService: síťová chyba, pokus {$attempt}/{$maxAttempts}", ['error' => $e->getMessage()]);

                usleep($delay * 1000);
                $delay *= $multiplier;
            }
        }

        throw new \RuntimeException('Claude API selhalo po všech pokusech');
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @param  string|array<int,array<string,mixed>>|null  $systemPrompt
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    protected function buildBody(array $messages, string|array|null $systemPrompt, array $options, string $model): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if ($systemPrompt !== null && $systemPrompt !== '') {
            $body['system'] = $systemPrompt;
        }

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        // `tools` (ADR-017) — jen converse() ho reálně nastavuje; complete()
        // options tento klíč nikdy neobsahují.
        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
        }

        return $body;
    }

    /**
     * @return array<string,string>
     */
    protected function buildHeaders(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => (string) config('chatbot.api.version', '2023-06-01'),
            'content-type' => 'application/json',
        ];
    }

    /**
     * Jednoduchý per-minutový rate limiter (cache-backed) — chrání proti
     * vyčerpání Anthropic limitů z více souběžných requestů (ADR-010).
     *
     * Bucket je oddělený dle účelu volání (bezpečnostní audit 2026-07-15, M1):
     * jeden sdílený globální klíč by znamenal, že ukecaný chat vyčerpá limit
     * business-kritickému účelu (a naopak). Limit se hledá v
     * `chatbot.rate.per_purpose.{purpose}` s fallbackem `chatbot.rate.default`
     * (ADR-019 §3 — purpose je volný string, balíček doménové účely nezná).
     *
     * VOLITELNÝ druhý bucket (TASK-103): `chatbot.rate.global_per_purpose.{purpose}`,
     * pokud je pro daný účel nastavený, platí SOUČASNĚ s per-user limitem (bucket bez
     * userId — omezuje součet přes všechny uživatele). Prázdná mapa (default) = beze
     * změny chování. Kontroluje se PŘED hitem obou bucketů, aby vyčerpaný per-user
     * bucket neinkrementoval globální (a naopak) — volání buď projde na obou, nebo na
     * žádném.
     *
     * @throws \RuntimeException Když je bucket (per-user nebo globální) vyčerpaný.
     */
    protected function checkRateLimit(string $purpose, mixed $user): void
    {
        // Pozn.: čte se přes pole, ne dotted klíčem `config("...{$purpose}")` —
        // purpose je volný string a tečka v něm by jinak procházela strom configu.
        $perPurpose = (array) config('chatbot.rate.per_purpose', []);
        $maxPerMinute = (int) ($perPurpose[$purpose] ?? config('chatbot.rate.default', 10));
        $key = $this->rateLimitKey($purpose, $user);

        if (RateLimiter::tooManyAttempts($key, $maxPerMinute)) {
            $seconds = RateLimiter::availableIn($key);

            throw new \RuntimeException("Překročen limit požadavků na Claude API ({$maxPerMinute}/min). Zkuste znovu za {$seconds} s.");
        }

        $globalPerPurpose = (array) config('chatbot.rate.global_per_purpose', []);
        $globalKey = null;
        $globalMax = null;

        if (array_key_exists($purpose, $globalPerPurpose)) {
            $globalMax = (int) $globalPerPurpose[$purpose];
            $globalKey = $this->globalRateLimitKey($purpose);

            if (RateLimiter::tooManyAttempts($globalKey, $globalMax)) {
                $seconds = RateLimiter::availableIn($globalKey);

                throw new \RuntimeException("Překročen globální limit požadavků na Claude API pro účel '{$purpose}' ({$globalMax}/min). Zkuste znovu za {$seconds} s.");
            }
        }

        RateLimiter::hit($key, 60);

        if ($globalKey !== null) {
            RateLimiter::hit($globalKey, 60);
        }
    }

    /**
     * Sestaví klíč cache limiteru — oddělený per účel + uživatel.
     */
    protected function rateLimitKey(string $purpose, mixed $user): string
    {
        return "ai-anthropic-{$purpose}:".($this->userId($user) ?? 'guest');
    }

    /**
     * Klíč globálního bucketu (TASK-103) — BEZ userId, platí napříč celou
     * aplikací pro daný účel.
     */
    protected function globalRateLimitKey(string $purpose): string
    {
        return "ai-anthropic-global:{$purpose}";
    }
}
