<?php

declare(strict_types=1);

use Webyashopy\Chatbot\ChatbotServiceProvider;

it('nabootuje service provider balíčku', function () {
    expect(app()->getLoadedProviders())
        ->toHaveKey(ChatbotServiceProvider::class);
});

it('načte config chatbot', function () {
    expect(config('chatbot'))->toBeArray()->not->toBeEmpty();
});

it('config má všechny bloky dle kontraktu', function () {
    // Pozn.: `user_model` je mimo blok `models` — ten je obsazený allowlistem
    // AI modelů (kolize klíčů v kontraktu, viz komentář v config/chatbot.php).
    $keys = ['user_model', 'models', 'features', 'routes', 'api', 'default_model', 'model',
        'pricing', 'retry', 'timeouts', 'rate', 'chat', 'tools', 'actions', 'prompts'];

    expect(array_keys(config('chatbot')))->toContain(...$keys);
});

it('config má očekávané výchozí hodnoty', function () {
    expect(config('chatbot.user_model'))->toBe('App\Models\User')
        ->and(config('chatbot.features.chat'))->toBeTrue()
        ->and(config('chatbot.routes.as'))->toBe('chat.')
        ->and(config('chatbot.routes.prefix'))->toBe('chat')
        ->and(config('chatbot.routes.middleware'))->toBe(['web', 'auth'])
        ->and(config('chatbot.api.url'))->toBe('https://api.anthropic.com/v1')
        ->and(config('chatbot.api.version'))->toBe('2023-06-01')
        ->and(config('chatbot.default_model'))->toBe('claude-sonnet-4-5-20250929')
        ->and(config('chatbot.model'))->toBe('claude-sonnet-4-5-20250929')
        ->and(config('chatbot.chat.history_limit'))->toBe(20)
        ->and(config('chatbot.chat.tools.enabled'))->toBeTrue()
        ->and(config('chatbot.chat.tools.max_iterations'))->toBe(5)
        ->and(config('chatbot.rate.per_purpose.chat'))->toBe(20)
        ->and(config('chatbot.rate.per_purpose.ocr'))->toBe(10)
        ->and(config('chatbot.rate.default'))->toBe(10)
        ->and(config('chatbot.retry'))->toBe(['max_attempts' => 3, 'delay_ms' => 1000, 'multiplier' => 2])
        ->and(config('chatbot.timeouts'))->toBe(['request' => 60, 'connect' => 10])
        ->and(config('chatbot.prompts.context'))->toBe('');
});

it('allowlist modelů a ceník sedí (každý model má cenu)', function () {
    $models = config('chatbot.models');
    $pricing = config('chatbot.pricing');

    expect($models)->toContain('claude-sonnet-4-5-20250929')
        ->and(config('chatbot.default_model'))->toBeIn($models)
        ->and(config('chatbot.chat.tools.capable_models'))->toBe($models);

    foreach ($models as $model) {
        expect($pricing)->toHaveKey($model)
            ->and($pricing[$model])->toHaveKeys(['input', 'output']);
    }
});

/*
 * `config:cache` serializuje config do PHP souboru — closure by ji shodila.
 * Ověřujeme obojí: reálný běh příkazu i to, že ve stromu configu žádná
 * closure není (serialize() na Closure vyhodí výjimku).
 */
it('config je cacheovatelný — neobsahuje closures', function () {
    expect(fn () => serialize(config('chatbot')))->not->toThrow(Exception::class);

    $this->artisan('config:cache')->assertSuccessful();
    $this->artisan('config:clear')->assertSuccessful();
});

it('discover_paths míří do host aplikace, ne do balíčku', function () {
    expect(config('chatbot.tools.discover_paths'))->toBe([app_path('Services/Ai/Tools')])
        ->and(config('chatbot.actions.discover_paths'))->toBe([app_path('Services/Ai/Actions')]);
});
