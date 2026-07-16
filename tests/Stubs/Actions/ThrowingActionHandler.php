<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Stubs\Actions;

use Illuminate\Support\Facades\Validator;
use Webyashopy\Chatbot\Contracts\ChatActionHandler;
use Webyashopy\Chatbot\Support\ChatActionResult;

/**
 * Handler, který zápis odmítne vyhozením `ValidationException` — přesně tak,
 * jak to udělá FormRequest hosta (`$formRequest->validateResolved()`, JNS
 * TASK-095).
 *
 * Balíček výjimku ZÁMĚRNĚ neodchytává: přesměrování 302 + `session('errors')`
 * udělá exception handler Laravelu. Tenhle stub hlídá, že se to nezmění
 * na 422 JSON (odchylka ADR-017 se posunout nesmí).
 */
class ThrowingActionHandler implements ChatActionHandler
{
    public function kind(): string
    {
        return 'test_vyjimka';
    }

    public function confirm(array $payload, mixed $user, array $context = []): ChatActionResult
    {
        Validator::make($payload, ['ico' => ['required']])->validate();

        return ChatActionResult::success(message: 'Sem se test nikdy nedostane.');
    }
}
