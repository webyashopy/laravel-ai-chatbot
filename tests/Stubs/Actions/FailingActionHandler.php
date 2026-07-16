<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Stubs\Actions;

use Webyashopy\Chatbot\Contracts\ChatActionHandler;
use Webyashopy\Chatbot\Support\ChatActionResult;

/**
 * Handler, který zápis odmítne s validačními chybami
 * ({@see ChatActionResult::failure()}).
 *
 * Ověřuje smluvní chování: 302 + `session('errors')`, NE 422 JSON
 * (vědomá odchylka ADR-017).
 */
class FailingActionHandler implements ChatActionHandler
{
    public function kind(): string
    {
        return 'test_neplatny';
    }

    public function confirm(array $payload, mixed $user, array $context = []): ChatActionResult
    {
        return ChatActionResult::failure(
            message: 'Zápis se nepodařil.',
            errors: ['ico' => ['IČO je povinné.']],
        );
    }
}
