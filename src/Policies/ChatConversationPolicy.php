<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Policies;

use Webyashopy\Chatbot\Models\ChatConversation;

/**
 * Policy konverzací (ADR-016) — konverzace jsou PRIVÁTNÍ vlastníkovi.
 * Žádné sdílené oprávnění, čistě ownership check (jiný uživatel → 403).
 *
 * `$user` je `mixed` — balíček host User model neimportuje (ADR-019),
 * porovnává se jen primární klíč.
 *
 * Případný admin bypass je věcí HOSTA (`Gate::before()`), ne balíčku —
 * balíček o rolích hosta nic neví.
 *
 * Registruje se explicitně v {@see \Webyashopy\Chatbot\ChatbotServiceProvider}:
 * konvenční auto-discovery Laravelu hledá policy v `App\Policies\` hosta,
 * což pro model z `vendor/` nikdy nesedne.
 */
class ChatConversationPolicy
{
    public function view(mixed $user, ChatConversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function update(mixed $user, ChatConversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function delete(mixed $user, ChatConversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    /**
     * Ownership check nad primárním klíčem uživatele.
     *
     * Porovnává se VOLNĚ (`==` po přetypování na string) záměrně: `user_id`
     * je v DB integer, ale host může mít klíč jako string (UUID). Chybějící
     * identita ani prázdný `user_id` nesmí projít.
     */
    private function owns(mixed $user, ChatConversation $conversation): bool
    {
        $userId = is_object($user) ? ($user->getKey() ?? null) : null;

        if ($userId === null || $conversation->user_id === null) {
            return false;
        }

        return (string) $userId === (string) $conversation->user_id;
    }
}
