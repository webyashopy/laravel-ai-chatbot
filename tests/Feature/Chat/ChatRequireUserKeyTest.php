<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Webyashopy\Chatbot\Models\ChatConversation;
use Webyashopy\Chatbot\Models\UserAiSettings;

/*
 * Striktní režim per-user klíčů v HTTP vrstvě chatu: uživatel bez vlastního
 * klíče dostane 302 + `errors.api_key` JEŠTĚ PŘED založením konverzace —
 * výjimka z AiService nesmí zapadnout v graceful catch větvi exchange().
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'chatbot.api.key' => 'env-server-key',
        'chatbot.api.require_user_key' => true,
        // Textový fallback — tool-loop tu není předmětem testu.
        'chatbot.chat.tools.capable_models' => [],
    ]);
});

/**
 * Jedna textová odpověď modelu.
 */
function fakeStrictModeReply(): void
{
    Http::fake(['*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Odpověď asistenta.']],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        'model' => 'claude-sonnet-5',
        'stop_reason' => 'end_turn',
    ], 200)]);
}

it('store bez vlastního klíče vrátí errors.api_key a nezaloží konverzaci', function () {
    $this->actingAsChatUser();
    Http::fake();

    $this->from('/chat')
        ->post('/chat', ['model' => 'claude-sonnet-5', 'message' => 'Dobrý den'])
        ->assertRedirect('/chat')
        ->assertSessionHasErrors('api_key');

    // Žádná prázdná konverzace, žádné volání API.
    expect(ChatConversation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('message bez vlastního klíče vrátí errors.api_key a neuloží zprávu', function () {
    $user = $this->actingAsChatUser();
    Http::fake();

    $conversation = ChatConversation::factory()->create(['user_id' => $user->id]);

    $this->from(route('chat.show', $conversation))
        ->post(route('chat.message', $conversation), ['message' => 'Dobrý den'])
        ->assertSessionHasErrors('api_key');

    expect($conversation->messages()->count())->toBe(0);
    Http::assertNothingSent();
});

it('store s vlastním klíčem projde a volá API user klíčem', function () {
    $user = $this->actingAsChatUser();
    fakeStrictModeReply();

    UserAiSettings::create(['user_id' => $user->id, 'api_key' => 'sk-ant-vlastni-klic-uzivatele']);

    $this->post('/chat', ['model' => 'claude-sonnet-5', 'message' => 'Dobrý den'])
        ->assertSessionDoesntHaveErrors();

    $conversation = ChatConversation::query()->firstOrFail();

    expect($conversation->messages()->count())->toBe(2);
    Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'sk-ant-vlastni-klic-uzivatele'));
});
