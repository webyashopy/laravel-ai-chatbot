<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Enums\ChatRole;
use Webyashopy\Chatbot\Models\ChatConversation;
use Webyashopy\Chatbot\Models\ChatMessage;
use Webyashopy\Chatbot\Tests\Stubs\Actions\FailingActionHandler;
use Webyashopy\Chatbot\Tests\Stubs\Actions\RecordingActionHandler;
use Webyashopy\Chatbot\Tests\Stubs\Actions\ThrowingActionHandler;

/*
 * Potvrzení navrženého zápisu (ADR-017 §4) — přeneseno z JNS
 * (tests/Feature/Chat/ChatActionConfirmTest.php, TASK-075) na balíčkové jádro.
 *
 * ZÁSADNÍ ROZDÍL oproti JNS: controller už nezná doménu. Dřívější
 * `match ($action['kind'])` → confirmCustomerOrder/confirmIncomingInvoice/
 * confirmPartner nahradil ChatActionHandlerRegistry (kind → handler hosta).
 * Balíček po potvrzení jen přepíše `action.status` a přesměruje dle
 * ChatActionResult; samotný zápis je věcí hosta.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Chatbot::flush();
    RecordingActionHandler::reset();

    Chatbot::registerActionHandler(RecordingActionHandler::class);
    Chatbot::registerActionHandler(FailingActionHandler::class);
    Chatbot::registerActionHandler(ThrowingActionHandler::class);
});

afterEach(function () {
    Chatbot::flush();
});

/**
 * Konverzace + asistentská zpráva s návrhem ve stavu `pending`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: ChatConversation, 1: ChatMessage}
 */
function pendingProposal(int $userId, string $kind = 'test_zaznam', array $overrides = []): array
{
    $conversation = ChatConversation::factory()->create(['user_id' => $userId]);

    $message = ChatMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => ChatRole::Assistant,
        'content' => 'Návrh čeká na potvrzení.',
        'action' => [
            'kind' => $kind,
            'payload' => ['ico' => '12345678'],
            'summary' => 'Návrh čeká na potvrzení.',
            'status' => 'pending',
            ...$overrides,
        ],
    ]);

    return [$conversation, $message];
}

it('potvrzení předá návrh handleru hosta a označí action jako confirmed', function () {
    $user = $this->actingAsChatUser();
    [$conversation, $message] = pendingProposal($user->id);

    $response = $this->post("/chat/{$conversation->id}/akce/potvrdit", [
        'message_id' => $message->id,
        'decision' => 'confirm',
    ]);

    $response->assertRedirect(route('chat.show', $conversation))
        ->assertSessionHas('toast.message', 'Záznam byl založen.');

    // Handler hosta dostal payload návrhu a identitu přihlášeného uživatele
    // (nikdy servisní identitu — ADR-017 §5).
    expect(RecordingActionHandler::$calls)->toBe(1)
        ->and(RecordingActionHandler::$payload)->toBe(['ico' => '12345678'])
        ->and(RecordingActionHandler::$user?->id)->toBe($user->id);

    $action = $message->fresh()->action;

    expect($action['status'])->toBe('confirmed')
        // `result_id` je smluvní (chatbot-tools.md) — FE na něj váže odkaz na záznam.
        ->and($action['result_id'])->toBe(42);
});

it('zrušení návrhu nic nezapíše a označí action jako cancelled', function () {
    $user = $this->actingAsChatUser();
    [$conversation, $message] = pendingProposal($user->id);

    $response = $this->post("/chat/{$conversation->id}/akce/potvrdit", [
        'message_id' => $message->id,
        'decision' => 'cancel',
    ]);

    $response->assertRedirect(route('chat.show', $conversation));

    expect(RecordingActionHandler::$calls)->toBe(0)
        ->and($message->fresh()->action['status'])->toBe('cancelled');
});

it('neznámý kind vrátí 422 a nikdy nezapíše', function () {
    $user = $this->actingAsChatUser();
    // Pro `kind` bez handleru host zápis nedodal — balíček ho nesmí vymyslet.
    [$conversation, $message] = pendingProposal($user->id, 'zadny_takovy_handler');

    $this->post("/chat/{$conversation->id}/akce/potvrdit", [
        'message_id' => $message->id,
        'decision' => 'confirm',
    ])->assertStatus(422);

    // Návrh zůstává pending — nic se nevyřídilo.
    expect($message->fresh()->action['status'])->toBe('pending');
});

it('validační chyba z handleru končí 302 + session errors, NE 422 JSON', function () {
    $user = $this->actingAsChatUser();
    [$conversation, $message] = pendingProposal($user->id, 'test_neplatny');

    $response = $this->from(route('chat.show', $conversation))
        ->post("/chat/{$conversation->id}/akce/potvrdit", [
            'message_id' => $message->id,
            'decision' => 'confirm',
        ]);

    // Vědomá odchylka ADR-017 — kontrakt chatbot-tools.md ji zamyká.
    $response->assertStatus(302)
        ->assertSessionHasErrors(['ico' => 'IČO je povinné.']);

    // Neúspěšný zápis nechává návrh pending — uživatel může opravit a potvrdit znovu.
    expect($message->fresh()->action['status'])->toBe('pending');
});

it('ValidationException z handleru (FormRequest hosta) končí taky 302 + session errors', function () {
    $user = $this->actingAsChatUser();
    // Payload BEZ `ico` → FormRequest hosta vyhodí ValidationException.
    [$conversation, $message] = pendingProposal($user->id, 'test_vyjimka', ['payload' => []]);

    $response = $this->from(route('chat.show', $conversation))
        ->post("/chat/{$conversation->id}/akce/potvrdit", [
            'message_id' => $message->id,
            'decision' => 'confirm',
        ]);

    $response->assertStatus(302)
        ->assertSessionHasErrors('ico');

    expect($message->fresh()->action['status'])->toBe('pending');
});

it('už vyřízený návrh vrátí 409 (idempotence)', function () {
    $user = $this->actingAsChatUser();
    [$conversation, $message] = pendingProposal($user->id, 'test_zaznam', ['status' => 'confirmed']);

    $this->post("/chat/{$conversation->id}/akce/potvrdit", [
        'message_id' => $message->id,
        'decision' => 'confirm',
    ])->assertStatus(409);

    expect(RecordingActionHandler::$calls)->toBe(0);
});

it('zpráva bez návrhu vrátí 404', function () {
    $user = $this->actingAsChatUser();
    $conversation = ChatConversation::factory()->create(['user_id' => $user->id]);
    $message = ChatMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => ChatRole::Assistant,
        'action' => null,
    ]);

    $this->post("/chat/{$conversation->id}/akce/potvrdit", [
        'message_id' => $message->id,
        'decision' => 'confirm',
    ])->assertStatus(404);
});

it('cizí konverzaci nelze potvrdit (403)', function () {
    $this->actingAsChatUser();

    $owner = $this->createUser();
    [$conversation, $message] = pendingProposal($owner->id);

    $this->post("/chat/{$conversation->id}/akce/potvrdit", [
        'message_id' => $message->id,
        'decision' => 'confirm',
    ])->assertStatus(403);

    expect(RecordingActionHandler::$calls)->toBe(0);
});

it('message_id z cizí konverzace nelze potvrdit přes vlastní konverzaci (IDOR)', function () {
    $user = $this->actingAsChatUser();

    // Vlastní konverzace (autorizace projde) …
    $own = ChatConversation::factory()->create(['user_id' => $user->id]);
    // … ale návrh patří do cizí.
    $owner = $this->createUser();
    [, $foreignMessage] = pendingProposal($owner->id);

    $this->post("/chat/{$own->id}/akce/potvrdit", [
        'message_id' => $foreignMessage->id,
        'decision' => 'confirm',
    ])->assertStatus(404);

    expect(RecordingActionHandler::$calls)->toBe(0)
        ->and($foreignMessage->fresh()->action['status'])->toBe('pending');
});
