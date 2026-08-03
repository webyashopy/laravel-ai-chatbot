<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Enums\ChatRole;
use Webyashopy\Chatbot\Models\ChatConversation;
use Webyashopy\Chatbot\Models\ChatMessage;
use Webyashopy\Chatbot\Tests\Stubs\Actions\RecordingActionHandler;

/*
 * Šifrování zpráv chatu (TASK-AIBOT-01g, ADR-095 §6) — config toggle
 * `chatbot.encrypt_messages` (default false).
 *
 * Testy pokrývají: default OFF beze změny chování (zbytek suite to hlídá
 * samo — casty jsou stejné jako před touto změnou), ON = ciphertext v DB
 * pro content/action/steps, roundtrip přes model, confirmAction flow
 * a titulek konverzace bez leaku textu uživatele.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['chatbot.api.key' => 'env-server-key']);
});

afterEach(function () {
    // Vrátit default, aby toggle neprosakoval do dalších testů souboru.
    config(['chatbot.encrypt_messages' => false]);
});

it('vypnutý toggle (default) ukládá content/action/steps beze změny', function () {
    config(['chatbot.encrypt_messages' => false]);

    $user = $this->actingAsChatUser();
    $conversation = ChatConversation::factory()->create(['user_id' => $user->id]);

    $message = ChatMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => ChatRole::Assistant,
        'content' => 'Plaintext odpověď.',
        'action' => ['kind' => 'test_zaznam', 'payload' => [], 'summary' => 's', 'status' => 'pending'],
        'steps' => [['tool' => 'read_cases', 'input' => [], 'summary' => 'ok']],
    ]);

    $raw = DB::table('chat_messages')->where('id', $message->id)->first();

    expect($raw->content)->toBe('Plaintext odpověď.')
        ->and(json_decode((string) $raw->action, true)['kind'])->toBe('test_zaznam');
});

it('zapnutý toggle šifruje content/action/steps v DB, roundtrip přes model čitelný', function () {
    config(['chatbot.encrypt_messages' => true]);

    $user = $this->actingAsChatUser();
    $conversation = ChatConversation::factory()->create(['user_id' => $user->id]);

    $message = ChatMessage::create([
        'conversation_id' => $conversation->id,
        'role' => ChatRole::Assistant,
        'content' => 'Citlivá zdravotní odpověď.',
        'action' => ['kind' => 'test_zaznam', 'payload' => ['ico' => '12345678'], 'summary' => 's', 'status' => 'pending'],
        'steps' => [['tool' => 'read_case_detail', 'input' => [], 'summary' => 'ok']],
    ]);

    // Surová DB hodnota nesmí obsahovat plaintext (ciphertext ≠ plaintext).
    $raw = DB::table('chat_messages')->where('id', $message->id)->first();

    expect($raw->content)->not->toBe('Citlivá zdravotní odpověď.')
        ->and($raw->content)->not->toContain('Citlivá')
        ->and($raw->action)->not->toContain('12345678')
        ->and($raw->steps)->not->toContain('read_case_detail');

    // Roundtrip přes Eloquent model dešifruje transparentně.
    $fresh = $message->fresh();

    expect($fresh->content)->toBe('Citlivá zdravotní odpověď.')
        ->and($fresh->action['kind'])->toBe('test_zaznam')
        ->and($fresh->action['payload']['ico'])->toBe('12345678')
        ->and($fresh->steps[0]['tool'])->toBe('read_case_detail');
});

it('zapnutý toggle: confirmAction flow funguje beze změny (šifrovaný action stále čitelný)', function () {
    config(['chatbot.encrypt_messages' => true]);

    Chatbot::flush();
    RecordingActionHandler::reset();
    Chatbot::registerActionHandler(RecordingActionHandler::class);

    $user = $this->actingAsChatUser();
    $conversation = ChatConversation::factory()->create(['user_id' => $user->id]);

    $message = ChatMessage::create([
        'conversation_id' => $conversation->id,
        'role' => ChatRole::Assistant,
        'content' => 'Návrh čeká na potvrzení.',
        'action' => [
            'kind' => 'test_zaznam',
            'payload' => ['ico' => '12345678'],
            'summary' => 'Návrh čeká na potvrzení.',
            'status' => 'pending',
        ],
    ]);

    $response = $this->post("/chat/{$conversation->id}/akce/potvrdit", [
        'message_id' => $message->id,
        'decision' => 'confirm',
    ]);

    $response->assertRedirect(route('chat.show', $conversation));

    expect(RecordingActionHandler::$calls)->toBe(1)
        ->and(RecordingActionHandler::$payload)->toBe(['ico' => '12345678'])
        ->and($message->fresh()->action['status'])->toBe('confirmed');

    Chatbot::flush();
});

it('zapnutý toggle: titulek konverzace neobsahuje text uživatele', function () {
    config(['chatbot.encrypt_messages' => true]);
    config(['chatbot.chat.tools.capable_models' => []]);

    $this->actingAsChatUser();

    Http::fake(['*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Odpověď.']],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        'model' => 'claude-sonnet-5',
        'stop_reason' => 'end_turn',
    ], 200)]);

    $this->post('/chat', [
        'model' => 'claude-sonnet-5',
        'message' => 'Mám podezření na frakturu obratle L4.',
    ]);

    $conversation = ChatConversation::query()->firstOrFail();

    expect($conversation->title)->not->toContain('frakturu')
        ->and($conversation->title)->not->toContain('obratle')
        ->and($conversation->title)->toStartWith('Konverzace ');

    // Surová DB hodnota titulku taky neleakuje text dotazu (title zůstává
    // plaintext sloupec — generický obsah je jediná ochrana, viz config).
    $raw = DB::table('chat_conversations')->where('id', $conversation->id)->first();
    expect($raw->title)->not->toContain('frakturu');
});

it('vypnutý toggle (default): titulek se dál plní z textu uživatele (beze změny chování)', function () {
    config(['chatbot.encrypt_messages' => false]);
    config(['chatbot.chat.tools.capable_models' => []]);

    $this->actingAsChatUser();

    Http::fake(['*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Odpověď.']],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        'model' => 'claude-sonnet-5',
        'stop_reason' => 'end_turn',
    ], 200)]);

    $this->post('/chat', [
        'model' => 'claude-sonnet-5',
        'message' => 'Dobrý den, mám dotaz',
    ]);

    $conversation = ChatConversation::query()->firstOrFail();

    expect($conversation->title)->toBe('Dobrý den, mám dotaz');
});
