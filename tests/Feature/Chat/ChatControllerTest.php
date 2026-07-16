<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Webyashopy\Chatbot\Enums\ChatRole;
use Webyashopy\Chatbot\Models\ChatConversation;
use Webyashopy\Chatbot\Models\ChatMessage;

/*
 * HTTP vrstva chatu (ADR-016) — přeneseno z JNS
 * (tests/Feature/Chat/ChatControllerTest.php) na balíčkový ChatController.
 *
 * Anthropic API je vždy `Http::fake` — žádné reálné volání.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['chatbot.api.key' => 'env-server-key']);
});

/**
 * Jedna textová odpověď modelu bez nástrojů.
 */
function fakeTextReply(string $text = 'Odpověď asistenta.'): void
{
    Http::fake(['*' => Http::response([
        'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        'model' => 'claude-sonnet-5',
        'stop_reason' => 'end_turn',
    ], 200)]);
}

it('routy chatu mají smluvní názvy a prefix', function () {
    // FE a Wayfinder stojí na názvech `chat.*` a prefixu `chat` (ADR-019 §11) —
    // extrakce do balíčku je nesmí posunout.
    expect(Route::has('chat.index'))->toBeTrue()
        ->and(Route::has('chat.show'))->toBeTrue()
        ->and(Route::has('chat.store'))->toBeTrue()
        ->and(Route::has('chat.message'))->toBeTrue()
        ->and(Route::has('chat.destroy'))->toBeTrue()
        ->and(Route::has('chat.action.confirm'))->toBeTrue()
        ->and(Route::getRoutes()->getByName('chat.index')->uri())->toBe('chat')
        ->and(Route::getRoutes()->getByName('chat.show')->uri())->toBe('chat/{conversation}')
        ->and(Route::getRoutes()->getByName('chat.message')->uri())->toBe('chat/{conversation}/zprava')
        ->and(Route::getRoutes()->getByName('chat.action.confirm')->uri())->toBe('chat/{conversation}/akce/potvrdit');
});

it('routy jsou array syntax — host přepíše controller přes IoC', function () {
    // Array syntax (`[ChatController::class, 'index']`) je smluvní: díky ní
    // host nabinduje vlastní controller a nemusí patchovat balíček.
    $action = Route::getRoutes()->getByName('chat.index')->getAction('controller');

    expect($action)->toBe(Webyashopy\Chatbot\Http\Controllers\ChatController::class.'@index');
});

it('index vypíše konverzace přihlášeného uživatele', function () {
    $user = $this->actingAsChatUser();

    ChatConversation::factory()->create(['user_id' => $user->id, 'title' => 'Moje konverzace']);
    // Cizí konverzace se do props nesmí dostat.
    ChatConversation::factory()->create(['user_id' => $this->createUser()->id, 'title' => 'Cizí']);

    $response = $this->withHeaders($this->inertiaHeaders())->get('/chat');

    $response->assertOk();

    $props = $response->json('props');

    expect($props['conversations'])->toHaveCount(1)
        ->and($props['conversations'][0]['title'])->toBe('Moje konverzace')
        ->and($props['active'])->toBeNull()
        ->and($props['default_model'])->toBe(config('chatbot.default_model'));
});

it('store založí konverzaci a uloží obě zprávy', function () {
    $user = $this->actingAsChatUser();
    fakeTextReply('Ahoj!');
    // Model mimo tool-capable seznam → textový fallback (complete()).
    config(['chatbot.chat.tools.capable_models' => []]);

    $response = $this->post('/chat', [
        'model' => 'claude-sonnet-5',
        'message' => 'Dobrý den',
    ]);

    $conversation = ChatConversation::query()->firstOrFail();

    $response->assertRedirect(route('chat.show', $conversation));

    expect($conversation->user_id)->toBe($user->id)
        ->and($conversation->title)->toBe('Dobrý den')
        ->and($conversation->messages()->count())->toBe(2);

    $assistant = $conversation->messages()->where('role', ChatRole::Assistant)->firstOrFail();

    expect($assistant->content)->toBe('Ahoj!')
        // Odpověď je navázaná na náklad volání (ADR-015).
        ->and($assistant->ai_usage_log_id)->not->toBeNull();
});

it('store odmítne model mimo allowlist (422)', function () {
    $this->actingAsChatUser();
    Http::fake();

    $this->post('/chat', [
        'model' => 'gpt-vlastni-model',
        'message' => 'Dobrý den',
    ])->assertStatus(422);

    // Žádný passthrough na Anthropic API (ADR-016).
    Http::assertNothingSent();
    expect(ChatConversation::query()->count())->toBe(0);
});

it('message přidá zprávu a přepne model konverzace', function () {
    $user = $this->actingAsChatUser();
    fakeTextReply();
    config(['chatbot.chat.tools.capable_models' => []]);

    $conversation = ChatConversation::factory()->create([
        'user_id' => $user->id,
        'model' => 'claude-sonnet-5',
    ]);

    $this->post("/chat/{$conversation->id}/zprava", [
        'message' => 'Další dotaz',
        'model' => 'claude-haiku-4-5',
    ])->assertRedirect(route('chat.show', $conversation));

    expect($conversation->fresh()->model)->toBe('claude-haiku-4-5')
        ->and($conversation->messages()->count())->toBe(2);
});

it('selhání AI nechá uživatelskou zprávu uloženou a konverzaci použitelnou', function () {
    $user = $this->actingAsChatUser();
    Http::fake(['*' => Http::response(['error' => 'nedostupné'], 500)]);
    config([
        'chatbot.chat.tools.capable_models' => [],
        // Bez zkrácení retry by test kvůli backoffu čekal několik sekund.
        'chatbot.retry.max_attempts' => 1,
    ]);

    $conversation = ChatConversation::factory()->create(['user_id' => $user->id]);

    $this->post("/chat/{$conversation->id}/zprava", ['message' => 'Dotaz'])
        ->assertRedirect(route('chat.show', $conversation));

    // Uživatelská zpráva zůstává, odpověď asistenta chybí — žádná 500.
    expect($conversation->messages()->count())->toBe(1)
        ->and($conversation->messages()->first()->role)->toBe(ChatRole::User);
});

it('destroy smaže vlastní konverzaci', function () {
    $user = $this->actingAsChatUser();
    $conversation = ChatConversation::factory()->create(['user_id' => $user->id]);

    $this->delete("/chat/{$conversation->id}")->assertRedirect(route('chat.index'));

    expect(ChatConversation::query()->count())->toBe(0);
});

it('cizí konverzaci nelze zobrazit, změnit ani smazat (403)', function () {
    $this->actingAsChatUser();

    $conversation = ChatConversation::factory()->create(['user_id' => $this->createUser()->id]);

    $this->withHeaders($this->inertiaHeaders())->get("/chat/{$conversation->id}")->assertStatus(403);
    $this->post("/chat/{$conversation->id}/zprava", ['message' => 'x'])->assertStatus(403);
    $this->delete("/chat/{$conversation->id}")->assertStatus(403);
});

it('show promítne kroky bez surových vstupů modelu', function () {
    $user = $this->actingAsChatUser();
    $conversation = ChatConversation::factory()->create(['user_id' => $user->id]);

    ChatMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => ChatRole::Assistant,
        'content' => 'Hotovo.',
        'steps' => [['tool' => 'read_zaznamy', 'input' => ['tajny_filtr' => 'x'], 'summary' => '2 záznamy']],
    ]);

    $response = $this->withHeaders($this->inertiaHeaders())->get("/chat/{$conversation->id}");

    $steps = $response->json('props.active.messages.0.steps');

    // Kontrakt chatbot-tools.md: do FE jde jen { tool, summary } — `input`
    // (surové filtry od modelu) se nepropisuje.
    expect($steps)->toBe([['tool' => 'read_zaznamy', 'summary' => '2 záznamy']]);
});
