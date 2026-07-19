<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Exceptions;

use RuntimeException;

/**
 * Striktní režim per-user klíčů (`chatbot.api.require_user_key`): volání
 * Anthropic API s uživatelem, který nemá vlastní klíč v `user_ai_settings`.
 *
 * Dědí z RuntimeException — stávající `catch (Throwable)` větve hostů
 * i balíčku ji zachytí beze změny; vlastní typ existuje proto, aby na ni
 * host mohl reagovat cíleně (výzva „nastavte si klíč“ místo obecné chyby).
 */
class MissingUserApiKeyException extends RuntimeException
{
    public function __construct(
        string $message = 'Chybí vlastní Anthropic API klíč. Nastavte si ho v nastavení chatu.',
    ) {
        parent::__construct($message);
    }
}
