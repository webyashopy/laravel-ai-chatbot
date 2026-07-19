<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Webyashopy\Chatbot\Exceptions\MissingUserApiKeyException;
use Webyashopy\Chatbot\Models\AiUsageLog;
use Webyashopy\Chatbot\Models\UserAiSettings;
use Webyashopy\Chatbot\Services\AiService;

/*
 * Striktní režim per-user klíčů (`chatbot.api.require_user_key`, TASK-104):
 * volání S uživatelem bez vlastního klíče se odmítne, env klíč zůstává jen
 * systémovým voláním bez usera.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'chatbot.api.key' => 'env-server-key',
        'chatbot.api.require_user_key' => true,
        'chatbot.user_model' => Webyashopy\Chatbot\Tests\Stubs\User::class,
    ]);
});

/**
 * Jedna úspěšná textová odpověď API.
 */
function fakeApiReply(): void
{
    Http::fake(['*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Odpověď.']],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        'model' => 'claude-haiku-4-5',
        'stop_reason' => 'end_turn',
    ], 200)]);
}

it('complete() s userem bez klíče vyhodí MissingUserApiKeyException', function () {
    Http::fake();
    $user = $this->createUser();

    $service = app(AiService::class);

    expect(fn (): array => $service->complete('test', null, ['user' => $user]))
        ->toThrow(MissingUserApiKeyException::class);

    // Výjimka letí před rate limitem i logováním — žádné volání, žádný log řádek.
    Http::assertNothingSent();
    expect(AiUsageLog::query()->count())->toBe(0);
});

it('complete() s userem a vlastním klíčem projde a použije user klíč', function () {
    fakeApiReply();
    $user = $this->createUser();
    UserAiSettings::create(['user_id' => $user->id, 'api_key' => 'sk-ant-vlastni-klic-uzivatele']);

    app(AiService::class)->complete('test', null, ['user' => $user]);

    Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'sk-ant-vlastni-klic-uzivatele'));
    expect(AiUsageLog::query()->firstOrFail()->key_source)->toBe('user');
});

it('complete() bez usera drží env fallback i ve striktním režimu', function () {
    // Systémová volání (chatbot:models-check, cron) žádného usera nemají —
    // striktní režim je nesmí odříznout od serverového klíče.
    fakeApiReply();

    app(AiService::class)->complete('test');

    Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'env-server-key'));
});

it('converse() s userem bez klíče vyhodí MissingUserApiKeyException', function () {
    Http::fake();
    $user = $this->createUser();

    $service = app(AiService::class);

    expect(fn (): array => $service->converse([['role' => 'user', 'content' => 'test']], null, ['user' => $user]))
        ->toThrow(MissingUserApiKeyException::class);

    Http::assertNothingSent();
});

it('vypnutý striktní režim (default) zachovává env fallback pro usera bez klíče', function () {
    config(['chatbot.api.require_user_key' => false]);
    fakeApiReply();
    $user = $this->createUser();

    app(AiService::class)->complete('test', null, ['user' => $user]);

    Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'env-server-key'));
    expect(AiUsageLog::query()->firstOrFail()->key_source)->toBe('env');
});
