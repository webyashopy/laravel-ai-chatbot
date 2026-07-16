<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Webyashopy\Chatbot\Models\AiUsageLog;
use Webyashopy\Chatbot\Services\AiService;
use Webyashopy\Chatbot\Support\Purpose;

/*
 * `chatbot.features.chat = false` (ADR-019 §9) — chat vrstva se neregistruje,
 * AI vrstva (klient + usage logging + per-user klíče) žije dál. To je celý
 * smysl přepínače: host, který chce jen `AiService` (např. OCR), nemá dostat
 * routy chatu.
 */

// ChatDisabledTestCase mapuje na tuhle složku tests/Pest.php.
uses(RefreshDatabase::class);

it('chat routy se neregistrují', function () {
    foreach (['chat.index', 'chat.show', 'chat.store', 'chat.message', 'chat.destroy', 'chat.action.confirm'] as $name) {
        expect(Route::has($name))->toBeFalse("Route [{$name}] nemá při vypnuté feature existovat.");
    }
});

it('chat URL vrátí 404', function () {
    $this->actingAsChatUser();

    $this->get('/chat')->assertStatus(404);
});

it('AI vrstva funguje dál — complete() zaloguje spotřebu', function () {
    config(['chatbot.api.key' => 'env-server-key']);

    Http::fake(['*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Odpověď.']],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        'model' => 'claude-haiku-4-5',
    ], 200)]);

    $user = $this->createUser();

    $result = app(AiService::class)->complete('Dotaz?', null, [
        'user' => $user,
        'purpose' => Purpose::CHAT,
    ]);

    expect($result['content'])->toBe('Odpověď.')
        ->and(AiUsageLog::query()->count())->toBe(1);
});

it('tabulky chatu se zmigrují i s vypnutou feature', function () {
    // Schéma DB nesmí záviset na runtime přepínači — jinak by pozdější zapnutí
    // chatu v už zmigrované DB tabulky nikdy nedoplnilo (migrace už „proběhla").
    expect(Schema::hasTable('chat_conversations'))->toBeTrue()
        ->and(Schema::hasTable('chat_messages'))->toBeTrue();
});
