<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Webyashopy\Chatbot\Models\AiUsageLog;
use Webyashopy\Chatbot\Models\UserAiSettings;
use Webyashopy\Chatbot\Services\AiService;
use Webyashopy\Chatbot\Support\Purpose;
use Webyashopy\Chatbot\Tests\TestCase;

/**
 * Feature testy `AiService` (přeneseno z JNS, ADR-015/TASK-062 → ADR-019/TASK-091)
 * — usage logging, resolution per-user→env klíče a výpočet ceny dle
 * `config('chatbot.pricing')`.
 *
 * Anthropic API je nahrazeno přes `Http::fake` — žádné reálné API volání.
 */
class AiServiceUsageLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'claude-haiku-4-5';

    /** Doménový účel hosta — balíček ho nezná, jen ho přebírá jako string (ADR-019 §3). */
    private const PURPOSE_OCR = 'ocr';

    protected function setUp(): void
    {
        parent::setUp();

        config(['chatbot.api.key' => 'env-server-key']);
        config(['chatbot.model' => self::MODEL]);
    }

    private function fakeSuccessResponse(int $inputTokens = 1000, int $outputTokens = 500): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);
    }

    public function test_call_without_user_creates_log_with_env_key_source(): void
    {
        $this->fakeSuccessResponse();

        /** @var AiService $service */
        $service = app(AiService::class);
        $service->complete('ahoj', null, ['purpose' => self::PURPOSE_OCR]);

        $this->assertDatabaseCount('ai_usage_logs', 1);

        $log = AiUsageLog::sole();
        $this->assertNull($log->user_id);
        $this->assertSame('env', $log->key_source);
        $this->assertSame(self::PURPOSE_OCR, $log->purpose);
        $this->assertTrue($log->success);
        $this->assertSame(self::MODEL, $log->model);

        Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'env-server-key'));
    }

    public function test_default_purpose_is_chat(): void
    {
        $this->fakeSuccessResponse();

        /** @var AiService $service */
        $service = app(AiService::class);
        $service->complete('ahoj');

        // Balíček nezná doménový 'ocr' — bez explicitního purpose loguje 'chat'.
        $this->assertSame(Purpose::CHAT, AiUsageLog::sole()->purpose);
    }

    public function test_user_without_own_api_key_falls_back_to_env_key(): void
    {
        $this->fakeSuccessResponse();

        $user = $this->createUser();

        /** @var AiService $service */
        $service = app(AiService::class);
        $service->complete('ahoj', null, ['user' => $user, 'purpose' => Purpose::CHAT]);

        $log = AiUsageLog::sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('env', $log->key_source);
        $this->assertSame(Purpose::CHAT, $log->purpose);

        Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'env-server-key'));
    }

    public function test_user_with_own_api_key_uses_it_instead_of_env(): void
    {
        $this->fakeSuccessResponse();

        $user = $this->createUser();
        UserAiSettings::create(['user_id' => $user->id, 'api_key' => 'user-secret-key']);

        /** @var AiService $service */
        $service = app(AiService::class);
        $service->complete('ahoj', null, ['user' => $user, 'purpose' => Purpose::CHAT]);

        $log = AiUsageLog::sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('user', $log->key_source);

        Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'user-secret-key'));
    }

    public function test_cost_is_calculated_according_to_pricing_config(): void
    {
        $this->fakeSuccessResponse(inputTokens: 1_000_000, outputTokens: 1_000_000);

        $pricing = config('chatbot.pricing.'.self::MODEL);
        $expectedCost = (float) $pricing['input'] + (float) $pricing['output'];

        /** @var AiService $service */
        $service = app(AiService::class);
        $service->complete('ahoj');

        $log = AiUsageLog::sole();
        $this->assertEqualsWithDelta($expectedCost, (float) $log->cost, 0.0001);
    }

    /**
     * ADR-015 §4 — neznámý model NESMÍ shodit volání, jen se nespočítá cena.
     */
    public function test_unknown_model_logs_cost_null_without_throwing(): void
    {
        $this->fakeSuccessResponse();

        /** @var AiService $service */
        $service = app(AiService::class);
        $service->complete('ahoj', null, ['model' => 'nejaky-neznamy-model']);

        $log = AiUsageLog::sole();
        $this->assertSame('nejaky-neznamy-model', $log->model);
        $this->assertNull($log->cost);
        $this->assertTrue($log->success);
    }

    public function test_api_error_still_creates_log_with_success_false(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['type' => 'authentication_error', 'message' => 'neplatny klic']], 401),
        ]);

        /** @var AiService $service */
        $service = app(AiService::class);

        try {
            $service->complete('ahoj');
            $this->fail('Očekávána RuntimeException.');
        } catch (\RuntimeException) {
            // očekávané chování — chyba se propaguje výše (host ji mapuje např. na 503)
        }

        $log = AiUsageLog::sole();
        $this->assertFalse($log->success);
        $this->assertNotNull($log->error);
    }

    /**
     * Bezpečnostní hardening (audit 2026-07-15, M1) — buckety rate limitu jsou
     * oddělené per účel, takže vyčerpání chatu nevyhladoví OCR (a naopak).
     * Limity se čtou z `chatbot.rate.per_purpose.*` (ADR-019 §3).
     */
    public function test_chat_rate_limit_bucket_nevyhladovi_ocr(): void
    {
        config(['chatbot.rate.per_purpose.chat' => 1]);
        $this->fakeSuccessResponse();

        $user = $this->createUser();

        /** @var AiService $service */
        $service = app(AiService::class);

        // Vyčerpá chat bucket uživatele.
        $service->complete('ahoj', null, ['user' => $user, 'purpose' => Purpose::CHAT]);

        try {
            $service->complete('ahoj znovu', null, ['user' => $user, 'purpose' => Purpose::CHAT]);
            $this->fail('Očekáván vyčerpaný chat rate limit.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Překročen limit', $e->getMessage());
        }

        // OCR (jiný účel = jiný bucket) musí projít beze změny chování.
        $service->complete('ocr dotaz', null, ['user' => $user, 'purpose' => self::PURPOSE_OCR]);

        $log = AiUsageLog::query()->where('purpose', self::PURPOSE_OCR)->sole();
        $this->assertTrue($log->success);
    }

    public function test_missing_api_key_creates_log_without_calling_ai(): void
    {
        config(['chatbot.api.key' => '']);

        Http::fake();

        /** @var AiService $service */
        $service = app(AiService::class);

        try {
            $service->complete('ahoj');
            $this->fail('Očekávána RuntimeException.');
        } catch (\RuntimeException) {
            // očekávané — chybějící klíč
        }

        Http::assertNothingSent();

        $log = AiUsageLog::sole();
        $this->assertFalse($log->success);
        $this->assertSame('env', $log->key_source);
    }
}
