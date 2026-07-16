<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Webyashopy\Chatbot\Models\AiUsageLog;
use Webyashopy\Chatbot\Services\AiService;
use Webyashopy\Chatbot\Support\Purpose;
use Webyashopy\Chatbot\Tests\TestCase;

/**
 * Feature testy `AiService::converse()` (přeneseno z JNS, ADR-017/TASK-073 →
 * ADR-019/TASK-091) — multi-turn volání s `tools`, plné content bloky
 * (text + tool_use), logování KAŽDÉHO round-tripu.
 *
 * Anthropic API je nahrazeno přes `Http::fake` — žádné reálné volání.
 *
 * Pozn.: `conversation_id` je v tomto tasku prostý identifikátor bez FK —
 * model `ChatConversation` a jeho tabulka přijdou až v TASK-094.
 */
class AiServiceConverseTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'claude-sonnet-5';

    protected function setUp(): void
    {
        parent::setUp();

        config(['chatbot.api.key' => 'env-server-key']);
    }

    public function test_converse_returns_full_content_blocks_and_stop_reason(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Podívám se do faktur.'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'read_faktury', 'input' => ['period' => '2026-07']],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                'stop_reason' => 'tool_use',
            ], 200),
        ]);

        $user = $this->createUser();

        /** @var AiService $service */
        $service = app(AiService::class);
        $result = $service->converse(
            [['role' => 'user', 'content' => 'Kolik mám faktur za červenec?']],
            'system prompt',
            ['user' => $user, 'model' => self::MODEL, 'conversation_id' => 42, 'tools' => [['name' => 'read_faktury']]],
        );

        $this->assertSame('tool_use', $result['stop_reason']);
        $this->assertSame(self::MODEL, $result['model']);
        $this->assertCount(2, $result['content']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame('tool_use', $result['content'][1]['type']);
        $this->assertSame('read_faktury', $result['content'][1]['name']);
        $this->assertSame(['period' => '2026-07'], $result['content'][1]['input']);

        $this->assertDatabaseCount('ai_usage_logs', 1);

        $log = AiUsageLog::sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(Purpose::CHAT, $log->purpose);
        $this->assertSame(42, $log->conversation_id);
        $this->assertTrue($log->success);
    }

    public function test_converse_body_includes_tools_when_provided(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $tools = [['name' => 'read_faktury', 'description' => 'test', 'input_schema' => ['type' => 'object']]];

        /** @var AiService $service */
        $service = app(AiService::class);
        $service->converse([['role' => 'user', 'content' => 'ahoj']], null, ['tools' => $tools]);

        Http::assertSent(fn ($request): bool => ($request->data()['tools'] ?? null) === $tools);
    }

    public function test_converse_omits_tools_key_when_not_provided(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        /** @var AiService $service */
        $service = app(AiService::class);
        $service->converse([['role' => 'user', 'content' => 'ahoj']]);

        Http::assertSent(fn ($request): bool => ! array_key_exists('tools', $request->data()));
    }

    public function test_converse_logs_each_round_trip_separately(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        /** @var AiService $service */
        $service = app(AiService::class);
        $service->converse([['role' => 'user', 'content' => 'první']], null, ['conversation_id' => 7]);
        $service->converse([['role' => 'user', 'content' => 'druhé']], null, ['conversation_id' => 7]);

        $this->assertDatabaseCount('ai_usage_logs', 2);
    }

    public function test_converse_error_still_creates_log_with_success_false(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['type' => 'authentication_error', 'message' => 'neplatny klic']], 401),
        ]);

        /** @var AiService $service */
        $service = app(AiService::class);

        try {
            $service->converse([['role' => 'user', 'content' => 'ahoj']]);
            $this->fail('Očekávána RuntimeException.');
        } catch (\RuntimeException) {
            // očekávané chování
        }

        $log = AiUsageLog::sole();
        $this->assertFalse($log->success);
        $this->assertNotNull($log->error);
    }

    public function test_complete_still_returns_flattened_text_content_unaffected_by_converse(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'část 1 '],
                    ['type' => 'text', 'text' => 'část 2'],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        /** @var AiService $service */
        $service = app(AiService::class);
        $result = $service->complete('ahoj');

        // complete() zůstává beze změny — content je zřetězený string, ne pole bloků.
        $this->assertIsString($result['content']);
        $this->assertSame('část 1 část 2', $result['content']);
    }
}
