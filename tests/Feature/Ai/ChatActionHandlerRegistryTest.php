<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Contracts\ChatActionHandler;
use Webyashopy\Chatbot\Services\ChatActionHandlerRegistry;
use Webyashopy\Chatbot\Tests\Support\HostFixture;

/**
 * Discovery handlerů potvrzení akcí (TASK-093) — stejný mechanismus jako
 * u nástrojů, jen nad `chatbot.actions.discover_paths`.
 */
beforeEach(function () {
    Chatbot::flush();
});

afterEach(function () {
    Chatbot::flush();
    HostFixture::cleanup();
});

it('najde handler v adresáři hosta a vrátí ho podle kind', function () {
    $host = HostFixture::make();
    $host->writeActionHandler('CustomerOrderActionHandler', 'customer_order');

    config(['chatbot.actions.discover_paths' => [$host->path]]);

    $registry = new ChatActionHandlerRegistry;
    $handler = $registry->get('customer_order');

    expect($registry->all())->toHaveCount(1)
        ->and($handler)->toBeInstanceOf(ChatActionHandler::class)
        ->and($registry->kinds())->toBe(['customer_order']);

    $result = $handler->confirm(['ico' => '12345678'], (object) ['id' => 1]);

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('Potvrzeno: customer_order');
});

it('neznámý kind vrátí null (nic se nesmí zapsat)', function () {
    $host = HostFixture::make();
    $host->writeActionHandler('KnownActionHandler', 'known');

    config(['chatbot.actions.discover_paths' => [$host->path]]);

    expect((new ChatActionHandlerRegistry)->get('neznamy_kind'))->toBeNull();
});

it('nový handler nevyžaduje editaci žádného sdíleného souboru', function () {
    $host = HostFixture::make();
    config(['chatbot.actions.discover_paths' => [$host->path]]);

    $host->writeActionHandler('FirstActionHandler', 'first');

    expect((new ChatActionHandlerRegistry)->kinds())->toBe(['first']);

    $host->writeActionHandler('Write/SecondActionHandler', 'second');

    expect((new ChatActionHandlerRegistry)->kinds())->toEqualCanonicalizing(['first', 'second']);
});

it('registerActionHandler doplní discovery o třídu mimo prohledávané cesty', function () {
    $host = HostFixture::make();
    $host->writeActionHandler('DiscoveredActionHandler', 'discovered');

    $outside = HostFixture::make();
    $manual = $outside->writeActionHandler('ManualActionHandler', 'manual');

    config(['chatbot.actions.discover_paths' => [$host->path]]);
    Chatbot::registerActionHandler($manual);

    expect((new ChatActionHandlerRegistry)->kinds())->toEqualCanonicalizing(['discovered', 'manual']);
});

/*
 * Regrese (stejná díra jako u nástrojů): guard na kořeni cesty nestačí,
 * `vendor/` může být podadresářem nakonfigurované cesty.
 */
it('nadřazená cesta nevytáhne handler z vendor/', function () {
    $vendor = base_path('vendor');

    $host = HostFixture::in($vendor.'/acme/evil/Actions', File::isDirectory($vendor) ? null : $vendor);
    $class = $host->writeActionHandler('EvilActionHandler', 'evil_action');

    config(['chatbot.actions.discover_paths' => [base_path()]]);

    expect(class_exists($class))->toBeTrue()
        ->and(is_subclass_of($class, ChatActionHandler::class))->toBeTrue();

    $registry = new ChatActionHandlerRegistry;

    expect($registry->get('evil_action'))->toBeNull()
        ->and($registry->kinds())->not->toContain('evil_action');
});

it('neskenuje adresář nástrojů (kontrakty se nemíchají)', function () {
    $tools = HostFixture::make();
    $tools->writeTool('SomeTool', 'read_neco');

    config(['chatbot.actions.discover_paths' => [$tools->path]]);

    expect((new ChatActionHandlerRegistry)->all())->toBe([]);
});

/*
 * Kill-switch nástrojů se na potvrzení akcí ZÁMĚRNĚ nevztahuje: vypnuté
 * nástroje znamenají, že nevzniknou nové návrhy, ale rozpracovaný návrh
 * v existující konverzaci musí jít stále potvrdit (potvrzení je akce
 * uživatele, ne modelu).
 */
it('kill-switch nástrojů neshodí registr action handlerů', function () {
    $host = HostFixture::make();
    $host->writeActionHandler('StillWorksActionHandler', 'still_works');

    config([
        'chatbot.actions.discover_paths' => [$host->path],
        'chatbot.chat.tools.enabled' => false,
    ]);

    expect((new ChatActionHandlerRegistry)->get('still_works'))->not->toBeNull();
});
