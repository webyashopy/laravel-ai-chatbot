<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Enums;

/**
 * Role autora zprávy v `chat_messages` (ADR-016).
 *
 * Hodnoty odpovídají rolím Anthropic Messages API (`user` / `assistant`)
 * a zároveň tvaru props pro frontend (kontrakt `chatbot-tools.md`) —
 * proto se nesmí měnit.
 */
enum ChatRole: string
{
    case User = 'user';
    case Assistant = 'assistant';

    /**
     * Lidsky čitelný název role.
     */
    public function label(): string
    {
        return match ($this) {
            self::User => 'Uživatel',
            self::Assistant => 'Asistent',
        };
    }
}
