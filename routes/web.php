<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Webyashopy\Chatbot\Http\Controllers\ChatController;

/*
 * Webové routy chatu. Načítá je ChatbotServiceProvider uvnitř skupiny
 * s prefixem, middlewarem a `as` z `config('chatbot.routes.*')` — a jen
 * tehdy, když je zapnutá feature `chatbot.features.chat` (ADR-019 §9).
 *
 * Názvy rout jsou proto psané BEZ prefixu `chat.` (dodá ho `as` ze skupiny)
 * a výsledek musí zůstat `chat.index` / `chat.show` / `chat.store` /
 * `chat.message` / `chat.destroy` / `chat.action.confirm` — frontend
 * a Wayfinder na těchto názvech stojí (ADR-019 §11).
 *
 * ARRAY SYNTAX (`[ChatController::class, 'index']`), ne invokable ani
 * string: díky ní host přepíše controller pouhým bindingem v kontejneru
 * (`$this->app->bind(ChatController::class, MyChatController::class)`),
 * bez patchování balíčku.
 */

Route::get('/', [ChatController::class, 'index'])->name('index');
Route::get('/{conversation}', [ChatController::class, 'show'])->name('show');
Route::delete('/{conversation}', [ChatController::class, 'destroy'])->name('destroy');

// Volání Anthropic API — pod rate limitem `chat` (registruje ChatbotServiceProvider
// z `chatbot.rate.per_purpose.chat`).
Route::middleware('throttle:chat')->group(function (): void {
    Route::post('/', [ChatController::class, 'store'])->name('store');
    Route::post('/{conversation}/zprava', [ChatController::class, 'message'])->name('message');
});

// Potvrzení/zrušení navrženého zápisu (ADR-017 §4) — nevolá Anthropic API,
// proto ZÁMĚRNĚ mimo `throttle:chat` výše (jinak by uživatel, který vyčerpal
// limit dotazů, nemohl vyřídit ani rozpracovaný návrh).
Route::post('/{conversation}/akce/potvrdit', [ChatController::class, 'confirmAction'])
    ->name('action.confirm');
