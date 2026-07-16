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
 *
 * ODCHYLKA od náčrtu v `contracts/api/chatbot-package.md`: navíc je zde
 * `$resultId`. Kontrakt `chatbot-tools.md` (ten se posunout NESMÍ) říká, že
 * po úspěšném potvrzení nese `action.result_id` id vytvořeného záznamu a
 * frontend (`chat-action-card`) na něj staví odkaz na zápis. Bez tohoto pole
 * by handler hosta id neměl jak vrátit a `result_id` by z action zmizelo.
 */
final class ChatActionResult
{
    /**
     * @param  bool  $ok  Proběhl zápis úspěšně?
     * @param  string|null  $message  Zpráva pro toast v UI.
     * @param  array<string, mixed>|null  $errors  Validační chyby → 302 + session('errors').
     * @param  string|null  $redirectRoute  Cíl přesměrování; null = default 'chat.show'.
     * @param  array<string, mixed>|null  $redirectParams  Parametry route pro přesměrování.
     * @param  int|string|null  $resultId  Id zapsaného záznamu → `action.result_id`.
     */
    public function __construct(
        public bool $ok,
        public ?string $message = null,
        public ?array $errors = null,
        public ?string $redirectRoute = null,
        public ?array $redirectParams = null,
        public int|string|null $resultId = null,
    ) {}

    /**
     * Úspěšný zápis — volitelně s id zapsaného záznamu a cílem přesměrování.
     *
     * @param  array<string, mixed>|null  $redirectParams
     */
    public static function success(
        ?string $message = null,
        int|string|null $resultId = null,
        ?string $redirectRoute = null,
        ?array $redirectParams = null,
    ): self {
        return new self(
            ok: true,
            message: $message,
            redirectRoute: $redirectRoute,
            redirectParams: $redirectParams,
            resultId: $resultId,
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
