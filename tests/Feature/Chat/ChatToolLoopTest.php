<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Enums\ChatRole;
use Webyashopy\Chatbot\Models\ChatConversation;
use Webyashopy\Chatbot\Tests\Stubs\Tools\ProposeStubTool;
use Webyashopy\Chatbot\Tests\Stubs\Tools\ReadStubTool;

/*
 * Tool-use smyčka přes HTTP vrstvu (ADR-017) — přeneseno z JNS
 * (tests/Feature/Chat/ChatToolLoopTest.php, TASK-076) na balíčkové jádro.
 *
 * Na rozdíl od AssistantServiceTest (jednotka smyčky) jde tady o celou cestu
 * request → smyčka → uložené `steps`/`action` a systémový prompt.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Chatbot::flush();
    ReadStubTool::reset();

    config([
        'chatbot.api.key' => 'env-server-key',
        // Nástroje hosta leží mimo discover_paths (tests/Stubs) — explicitní registrace.
        'chatbot.chat.tools.enabled' => true,
    ]);

    Chatbot::registerTool(ReadStubTool::class);
    Chatbot::registerTool(ProposeStubTool::class);
});

afterEach(function () {
    Chatbot::flush();
});

it('read nástroj proběhne pod identitou přihlášeného uživatele a uloží kroky', function () {
    $user = $this->actingAsChatUser();

    Http::fake(['*' => Http::sequence()
        ->push([
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'read_zaznamy', 'input' => []]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'stop_reason' => 'tool_use',
        ], 200)
        ->push([
            'content' => [['type' => 'text', 'text' => 'Máte 2 záznamy.']],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    $this->post('/chat', [
        'model' => 'claude-sonnet-5',
        'message' => 'Kolik mám záznamů?',
    ])->assertRedirect();

    $conversation = ChatConversation::query()->firstOrFail();
    $assistant = $conversation->messages()->where('role', ChatRole::Assistant)->firstOrFail();

    expect($assistant->content)->toBe('Máte 2 záznamy.')
        ->and($assistant->steps)->toHaveCount(1)
        ->and($assistant->steps[0]['tool'])->toBe('read_zaznamy')
        // Nástroj běží POD PRÁVY uživatele, nikdy servisní identita (ADR-017 §5).
        ->and(ReadStubTool::$user?->id)->toBe($user->id)
        // Read nástroj nic nenavrhuje.
        ->and($assistant->action)->toBeNull();
});

it('proposal ukončí smyčku a uloží pending action, nic nezapíše', function () {
    $this->actingAsChatUser();

    // Model si řekne o write nástroj. Kdyby smyčka po proposalu pokračovala,
    // druhá odpověď by ji hnala dál — Http::assertSentCount(1) to zamyká.
    Http::fake(['*' => Http::response([
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'propose_zaznam', 'input' => ['ico' => '12345678']]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        'stop_reason' => 'tool_use',
    ], 200)]);

    $this->post('/chat', [
        'model' => 'claude-sonnet-5',
        'message' => 'Založ záznam',
    ])->assertRedirect();

    $conversation = ChatConversation::query()->firstOrFail();
    $assistant = $conversation->messages()->where('role', ChatRole::Assistant)->firstOrFail();

    expect($assistant->action['kind'])->toBe('test_zaznam')
        // Human-in-the-loop: návrh čeká na potvrzení, zápis NEPROBĚHL (ADR-017 §4).
        ->and($assistant->action['status'])->toBe('pending')
        ->and($assistant->action['payload'])->toBe(['ico' => '12345678'])
        ->and($assistant->content)->toBe('Návrh záznamu čeká na potvrzení.');

    // Proposal smyčku ukončil OKAMŽITĚ — žádný další round-trip.
    Http::assertSentCount(1);
});

it('kill-switch vypne nástroje — model je nedostane a jede textový fallback', function () {
    $this->actingAsChatUser();
    config(['chatbot.chat.tools.enabled' => false]);

    Http::fake(['*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Bez nástrojů.']],
        'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
        'model' => 'claude-sonnet-5',
    ], 200)]);

    $this->post('/chat', [
        'model' => 'claude-sonnet-5',
        'message' => 'Kolik mám záznamů?',
    ])->assertRedirect();

    Http::assertSent(fn ($request) => ! isset($request['tools']));
});

it('systémový prompt nese fixní preambuli balíčku i doménový kontext hosta', function () {
    $this->actingAsChatUser();
    config(['chatbot.prompts.context' => 'Jsi asistent v testovací doméně.']);

    Http::fake(['*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Odpověď.']],
        'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
        'stop_reason' => 'end_turn',
    ], 200)]);

    $this->post('/chat', [
        'model' => 'claude-sonnet-5',
        'message' => 'Ahoj',
    ])->assertRedirect();

    Http::assertSent(function ($request) {
        $system = $request['system'];

        // Preambule je FIXNÍ v balíčku (host ji nepřepíše, ADR-019 §7) …
        return str_contains($system, 'human-in-the-loop')
            // … a doménový kontext hosta se k ní jen připojí.
            && str_contains($system, 'Jsi asistent v testovací doméně.');
    });
});
