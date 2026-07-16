<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Support;

/**
 * Výsledek potvrzení navržené akce
 * ({@see \Webyashopy\Chatbot\Contracts\ChatActionHandler::confirm()}).
 *
 * Chování při validační chybě je smluvní a NESMÍ se posunout: controller
 * odpoví 302 + `session('errors')`, ne 422 JSON (odchylka zaznamenaná
 * v ADR-017).
 */
final class ChatActionResult
{
    /**
     * @param  bool  $ok  Proběhl zápis úspěšně?
     * @param  string|null  $message  Zpráva pro toast v UI.
     * @param  array<string, mixed>|null  $errors  Validační chyby → 302 + session('errors').
     * @param  string|null  $redirectRoute  Cíl přesměrování; null = default 'chat.show'.
     * @param  array<string, mixed>|null  $redirectParams  Parametry route pro přesměrování.
     */
    public function __construct(
        public bool $ok,
        public ?string $message = null,
        public ?array $errors = null,
        public ?string $redirectRoute = null,
        public ?array $redirectParams = null,
    ) {}

    /**
     * Úspěšný zápis — volitelně s cílem přesměrování na vytvořený záznam.
     *
     * @param  array<string, mixed>|null  $redirectParams
     */
    public static function success(
        ?string $message = null,
        ?string $redirectRoute = null,
        ?array $redirectParams = null,
    ): self {
        return new self(
            ok: true,
            message: $message,
            redirectRoute: $redirectRoute,
            redirectParams: $redirectParams,
        );
    }

    /**
     * Neúspěch — validační chyby, případně zpráva pro toast.
     *
     * @param  array<string, mixed>|null  $errors
     */
    public static function failure(?string $message = null, ?array $errors = null): self
    {
        return new self(ok: false, message: $message, errors: $errors);
    }
}
