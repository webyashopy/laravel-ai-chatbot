<?php

declare(strict_types=1);

use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Contracts\ChatActionHandler;
use Webyashopy\Chatbot\Contracts\ChatAuthorizer;
use Webyashopy\Chatbot\Contracts\ChatTool;
use Webyashopy\Chatbot\Support\AllowAuthenticatedChatAuthorizer;
use Webyashopy\Chatbot\Support\ChatActionResult;
use Webyashopy\Chatbot\Support\Purpose;

it('nabinduje ChatAuthorizer na AllowAuthenticatedChatAuthorizer', function () {
    expect(app(ChatAuthorizer::class))->toBeInstanceOf(AllowAuthenticatedChatAuthorizer::class);
});

it('host přepíše default vlastním bindingem', function () {
    // Balíček binduje přes bind() (ne singleton) — host aplikace si ve svém
    // provideru nabinduje vlastní implementaci a musí vyhrát.
    $this->app->bind(ChatAuthorizer::class, DenyAllChatAuthorizer::class);

    expect(app(ChatAuthorizer::class))->toBeInstanceOf(DenyAllChatAuthorizer::class)
        ->and(app(ChatAuthorizer::class)->canUseChat((object) ['id' => 1]))->toBeFalse();
});

it('AllowAuthenticatedChatAuthorizer pustí jen přihlášeného', function () {
    $authorizer = new AllowAuthenticatedChatAuthorizer();
    $user = (object) ['id' => 7];

    expect($authorizer->canUseChat($user))->toBeTrue()
        ->and($authorizer->canUseChat(null))->toBeFalse()
        ->and($authorizer->canConfirmAction($user, 'customer_order'))->toBeTrue()
        ->and($authorizer->canConfirmAction(null, 'customer_order'))->toBeFalse();
});

it('ChatActionResult nese výsledek potvrzení akce', function () {
    $ok = ChatActionResult::success('Objednávka vytvořena', 'objednavky.show', ['objednavka' => 3]);

    expect($ok->ok)->toBeTrue()
        ->and($ok->message)->toBe('Objednávka vytvořena')
        ->and($ok->errors)->toBeNull()
        ->and($ok->redirectRoute)->toBe('objednavky.show')
        ->and($ok->redirectParams)->toBe(['objednavka' => 3]);

    $fail = ChatActionResult::failure('Chyba', ['ico' => ['IČO je povinné.']]);

    expect($fail->ok)->toBeFalse()
        ->and($fail->errors)->toBe(['ico' => ['IČO je povinné.']])
        ->and($fail->redirectRoute)->toBeNull();
});

it('Purpose::CHAT je volný string s odpovídajícím rate limitem', function () {
    expect(Purpose::CHAT)->toBe('chat')
        ->and(config('chatbot.rate.per_purpose.'.Purpose::CHAT))->toBe(20);
});

it('registr přijme nástroj i action handler a je idempotentní', function () {
    Chatbot::flush();

    Chatbot::registerTool(DummyChatTool::class);
    Chatbot::registerTool(DummyChatTool::class);
    Chatbot::registerActionHandler(DummyActionHandler::class);

    expect(Chatbot::registeredTools())->toBe([DummyChatTool::class])
        ->and(Chatbot::registeredActionHandlers())->toBe([DummyActionHandler::class]);

    Chatbot::flush();

    expect(Chatbot::registeredTools())->toBeEmpty()
        ->and(Chatbot::registeredActionHandlers())->toBeEmpty();
});

it('registr odmítne třídu bez kontraktu', function () {
    Chatbot::registerTool(DenyAllChatAuthorizer::class);
})->throws(InvalidArgumentException::class);

/*
 * Testovací dvojníky — zastupují implementace, které dodává host aplikace.
 */

class DenyAllChatAuthorizer implements ChatAuthorizer
{
    public function canUseChat(mixed $user): bool
    {
        return false;
    }

    public function canConfirmAction(mixed $user, string $kind): bool
    {
        return false;
    }
}

class DummyChatTool implements ChatTool
{
    public function name(): string
    {
        return 'read_dummy';
    }

    public function definition(): array
    {
        return ['name' => 'read_dummy', 'description' => 'Dummy', 'input_schema' => []];
    }

    public function handle(array $input, mixed $user): array
    {
        return ['rows' => []];
    }
}

class DummyActionHandler implements ChatActionHandler
{
    public function kind(): string
    {
        return 'dummy';
    }

    public function confirm(array $payload, mixed $user): ChatActionResult
    {
        return ChatActionResult::success('OK');
    }
}
