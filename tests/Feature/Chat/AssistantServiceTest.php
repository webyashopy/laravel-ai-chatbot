<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Webyashopy\Chatbot\Contracts\ChatTool;
use Webyashopy\Chatbot\Services\AssistantService;
use Webyashopy\Chatbot\Services\ChatToolRegistry;

/*
 * Testy agentní smyčky (ADR-017) — přeneseno z JNS (tests/Feature/Ai/AssistantServiceTest.php,
 * TASK-073) na balíčkové třídy a `mixed $user`.
 *
 * `ChatToolRegistry` je nahrazen mockem (self-discovery nad reálným FS testuje
 * samostatně ChatToolRegistryTest) — tady jde jen o orchestraci smyčky.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'chatbot.api.key' => 'env-server-key',
        'chatbot.user_model' => Webyashopy\Chatbot\Tests\Stubs\User::class,
    ]);
});

/**
 * @param  array<int, array<string, mixed>>  $responses
 */
function chatResponseSequence(array $responses): void
{
    Http::fake(['*' => Http::sequence(array_map(
        static fn (array $r) => Http::response($r, 200),
        $responses,
    ))]);
}

/**
 * @param  array<int, array<string, mixed>>  $definitions
 */
function bindChatRegistry(array $definitions, ?ChatTool $tool = null): void
{
    $registry = Mockery::mock(ChatToolRegistry::class);
    $registry->shouldReceive('definitions')->andReturn($definitions);

    if ($tool !== null) {
        $registry->shouldReceive('get')->with($tool->name())->andReturn($tool);
    }

    $registry->shouldReceive('get')->andReturn(null)->byDefault();

    app()->instance(ChatToolRegistry::class, $registry);
}

it('smyčka končí hned, když model nechce nástroj', function () {
    bindChatRegistry([]);
    chatResponseSequence([
        ['content' => [['type' => 'text', 'text' => 'Ahoj, jak mohu pomoci?']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 5], 'stop_reason' => 'end_turn'],
    ]);

    $user = $this->createUser();

    $result = app(AssistantService::class)->run([['role' => 'user', 'content' => 'ahoj']], null, $user);

    expect($result['text'])->toBe('Ahoj, jak mohu pomoci?')
        ->and($result['steps'])->toBe([])
        ->and($result['stop_reason'])->toBe('end_turn')
        ->and($result['action'])->toBeNull();

    $this->assertDatabaseCount('ai_usage_logs', 1);
});

it('provede nástroj a zavolá converse znovu', function () {
    $tool = new class implements ChatTool
    {
        public function name(): string
        {
            return 'read_faktury';
        }

        public function definition(): array
        {
            return ['name' => 'read_faktury', 'description' => 'test', 'input_schema' => ['type' => 'object']];
        }

        public function handle(array $input, mixed $user): array
        {
            return ['summary' => '3 faktury nalezeny', 'items' => []];
        }
    };

    bindChatRegistry([$tool->definition()], $tool);
    chatResponseSequence([
        [
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'read_faktury', 'input' => ['period' => '2026-07']]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'stop_reason' => 'tool_use',
        ],
        [
            'content' => [['type' => 'text', 'text' => 'Za červenec máte 3 faktury.']],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
            'stop_reason' => 'end_turn',
        ],
    ]);

    $user = $this->createUser();

    $result = app(AssistantService::class)->run([['role' => 'user', 'content' => 'Kolik mám faktur?']], null, $user);

    expect($result['text'])->toBe('Za červenec máte 3 faktury.')
        ->and($result['steps'])->toHaveCount(1)
        ->and($result['steps'][0]['tool'])->toBe('read_faktury')
        ->and($result['steps'][0]['summary'])->toBe('3 faktury nalezeny')
        ->and($result['stop_reason'])->toBe('end_turn')
        ->and($result['action'])->toBeNull();

    Http::assertSentCount(2);
    $this->assertDatabaseCount('ai_usage_logs', 2);
});

it('neznámý nástroj vrátí chybu v tool_result bez pádu smyčky', function () {
    bindChatRegistry([['name' => 'neexistujici_nastroj']]);
    chatResponseSequence([
        [
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'neexistujici_nastroj', 'input' => []]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'stop_reason' => 'tool_use',
        ],
        [
            'content' => [['type' => 'text', 'text' => 'Omlouvám se, tento nástroj neznám.']],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
            'stop_reason' => 'end_turn',
        ],
    ]);

    $user = $this->createUser();

    $result = app(AssistantService::class)->run([['role' => 'user', 'content' => 'test']], null, $user);

    expect($result['text'])->toBe('Omlouvám se, tento nástroj neznám.')
        ->and($result['steps'])->toHaveCount(1)
        ->and($result['steps'][0]['summary'])->toBe('Neznámý nástroj');

    Http::assertSentCount(2);
});

it('smyčka respektuje tvrdý limit max_iterations', function () {
    config(['chatbot.chat.tools.max_iterations' => 2]);

    $tool = new class implements ChatTool
    {
        public function name(): string
        {
            return 'looping_tool';
        }

        public function definition(): array
        {
            return ['name' => 'looping_tool'];
        }

        public function handle(array $input, mixed $user): array
        {
            return ['summary' => 'ok'];
        }
    };

    bindChatRegistry([$tool->definition()], $tool);

    // Model si vždy řekne o nástroj — bez tvrdého limitu by smyčka běžela navěky.
    Http::fake([
        '*' => Http::response([
            'content' => [['type' => 'tool_use', 'id' => 'toolu_x', 'name' => 'looping_tool', 'input' => []]],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            'stop_reason' => 'tool_use',
        ], 200),
    ]);

    $user = $this->createUser();

    $result = app(AssistantService::class)->run([['role' => 'user', 'content' => 'test']], null, $user);

    expect($result['stop_reason'])->toBe('tool_use')
        ->and($result['steps'])->toHaveCount(2);

    Http::assertSentCount(2);
    $this->assertDatabaseCount('ai_usage_logs', 2);
});

it('proposal z write nástroje ukončí smyčku a vrátí pending action', function () {
    $tool = new class implements ChatTool
    {
        public function name(): string
        {
            return 'propose_zaznam';
        }

        public function definition(): array
        {
            return ['name' => 'propose_zaznam'];
        }

        public function handle(array $input, mixed $user): array
        {
            return [
                'status' => 'proposal',
                'kind' => 'zaznam',
                'payload' => ['castka' => 1000],
                'summary' => 'Návrh čeká na potvrzení.',
            ];
        }
    };

    bindChatRegistry([$tool->definition()], $tool);
    chatResponseSequence([
        [
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'propose_zaznam', 'input' => ['castka' => 1000]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'stop_reason' => 'tool_use',
        ],
    ]);

    $user = $this->createUser();

    $result = app(AssistantService::class)->run([['role' => 'user', 'content' => 'Zapiš 1000 Kč']], null, $user);

    expect($result['action'])->not->toBeNull()
        ->and($result['action']['kind'])->toBe('zaznam')
        ->and($result['action']['status'])->toBe('pending')
        ->and($result['action']['payload'])->toBe(['castka' => 1000])
        ->and($result['text'])->toBe('Návrh čeká na potvrzení.');

    // Proposal smyčku ukončí OKAMŽITĚ — žádný druhý round-trip navíc.
    Http::assertSentCount(1);
    $this->assertDatabaseCount('ai_usage_logs', 1);
});
