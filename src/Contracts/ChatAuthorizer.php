<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Contracts;

/**
 * Kontrakt pro autorizaci chatu — kdo smí chat používat a kdo smí
 * potvrdit navržený zápis (proposal → zápis v hostovi).
 *
 * Balíček nemá vlastní RBAC a NEZÁVISÍ na žádném balíčku rolí/oprávnění
 * (např. Spatie Permission). Role řeší výhradně host aplikace svou
 * implementací tohoto kontraktu.
 *
 * Balíček dodává default {@see \Webyashopy\Chatbot\Support\AllowAuthenticatedChatAuthorizer}
 * (chat smí kdokoli přihlášený). Host si v service provideru nabinduje
 * vlastní implementaci — např. JNS `PermissionChatAuthorizer`
 * (`$user->can('chat.use')`).
 *
 * Parametr `$user` je typovaný `mixed`, PROTOŽE balíček host User model
 * neimportuje: konkrétní třída uživatele vzniká až v host aplikaci
 * (viz `chatbot.models.user_model`). Tvrdá vazba na `App\Models\User` by
 * balíček přivázala k jedné aplikaci a znemožnila znovupoužití.
 *
 * Pozn.: tento kontrakt nenahrazuje defense-in-depth — každý tool handler
 * se stále re-autorizuje pod `Auth::user()` (ADR-017 §5).
 */
interface ChatAuthorizer
{
    /**
     * Smí uživatel používat chat (otevřít konverzaci, poslat zprávu)?
     *
     * @param  mixed  $user  Autentizovaný uživatel host aplikace (nebo null).
     */
    public function canUseChat(mixed $user): bool;

    /**
     * Smí uživatel potvrdit navrženou akci daného druhu?
     *
     * @param  mixed  $user  Autentizovaný uživatel host aplikace (nebo null).
     * @param  string  $kind  Druh akce dle {@see ChatActionHandler::kind()},
     *                        např. 'customer_order'.
     */
    public function canConfirmAction(mixed $user, string $kind): bool;
}
