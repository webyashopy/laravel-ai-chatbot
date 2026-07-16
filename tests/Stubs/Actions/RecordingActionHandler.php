<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Stubs\Actions;

use Webyashopy\Chatbot\Contracts\ChatActionHandler;
use Webyashopy\Chatbot\Support\ChatActionResult;

/**
 * Úspěšný handler hosta — zaznamená, s čím ho balíček zavolal.
 *
 * Zastupuje doménový handler JNS (TASK-095): balíček o jeho vnitřku nic neví,
 * jen mu předá payload + uživatele a promítne {@see ChatActionResult}.
 */
class RecordingActionHandler implements ChatActionHandler
{
    /** @var array<string, mixed>|null Payload, se kterým byl handler zavolán. */
    public static ?array $payload = null;

    /** Uživatel, pod jehož identitou byl handler zavolán. */
    public static mixed $user = null;

    public static int $calls = 0;

    public function kind(): string
    {
        return 'test_zaznam';
    }

    public function confirm(array $payload, mixed $user, array $context = []): ChatActionResult
    {
        self::$payload = $payload;
        self::$user = $user;
        self::$calls++;

        return ChatActionResult::success(message: 'Záznam byl založen.', resultId: 42);
    }

    /**
     * Reset mezi testy — statický stav by jinak protekl dál.
     */
    public static function reset(): void
    {
        self::$payload = null;
        self::$user = null;
        self::$calls = 0;
    }
}
