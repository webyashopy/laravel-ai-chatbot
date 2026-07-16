<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Support;

use Webyashopy\Chatbot\Contracts\ChatAuthorizer;

/**
 * Výchozí default pro {@see ChatAuthorizer}.
 *
 * Nejjednodušší možná autorizace: chat i potvrzení akce smí kdokoli
 * přihlášený (`$user !== null`). Balíček záměrně nezná role — host
 * aplikace s RBAC tento default přepíše vlastním bindingem v service
 * provideru (JNS: `PermissionChatAuthorizer` → `$user->can('chat.use')`).
 */
class AllowAuthenticatedChatAuthorizer implements ChatAuthorizer
{
    /**
     * Chat smí kdokoli přihlášený.
     */
    public function canUseChat(mixed $user): bool
    {
        return $user !== null;
    }

    /**
     * Potvrzení akce má stejné pravidlo jako použití chatu — druh akce
     * (`$kind`) default nerozlišuje, to je věc hostova RBAC.
     */
    public function canConfirmAction(mixed $user, string $kind): bool
    {
        return $user !== null;
    }
}
