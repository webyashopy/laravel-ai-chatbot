<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Webyashopy\Chatbot\Models\UserAiSettings;

/*
 * Self-service nastavení per-user API klíče (ChatSettingsController).
 *
 * Bezpečnostní invariant: klíč se do FE NIKDY neposílá — props nesou jen
 * `has_api_key: bool`; v DB leží zašifrovaný (`encrypted` cast).
 */

uses(RefreshDatabase::class);

it('routy nastavení mají smluvní názvy a prefix', function () {
    expect(Route::has('chat.settings.show'))->toBeTrue()
        ->and(Route::has('chat.settings.update'))->toBeTrue()
        ->and(Route::has('chat.settings.key.destroy'))->toBeTrue()
        ->and(Route::getRoutes()->getByName('chat.settings.show')->uri())->toBe('chat/nastaveni')
        ->and(Route::getRoutes()->getByName('chat.settings.update')->uri())->toBe('chat/nastaveni')
        ->and(Route::getRoutes()->getByName('chat.settings.key.destroy')->uri())->toBe('chat/nastaveni/klic');
});

it('GET /chat/nastaveni nespadne do wildcard show konverzace', function () {
    // `/{conversation}` je registrované ZA settings routami a s `whereNumber` —
    // bez obojího by `nastaveni` skončilo jako route-model binding konverzace (404).
    $this->actingAsChatUser();

    $response = $this->withHeaders($this->inertiaHeaders())->get('/chat/nastaveni');

    $response->assertOk();

    expect($response->json('component'))->toBe('chat/settings');
});

it('show vrací has_api_key=false bez uloženého klíče', function () {
    $this->actingAsChatUser();

    $props = $this->withHeaders($this->inertiaHeaders())
        ->get('/chat/nastaveni')
        ->json('props');

    expect($props['has_api_key'])->toBeFalse()
        ->and($props['preferred_model'])->toBeNull()
        ->and($props['require_user_key'])->toBeFalse();
});

it('show vrací has_api_key=true a klíč do props neunikne', function () {
    $user = $this->actingAsChatUser();

    UserAiSettings::create(['user_id' => $user->id, 'api_key' => 'sk-ant-tajny-klic-uzivatele']);

    $response = $this->withHeaders($this->inertiaHeaders())->get('/chat/nastaveni');

    expect($response->json('props.has_api_key'))->toBeTrue()
        // Plaintext klíče se nesmí objevit NIKDE v odpovědi.
        ->and($response->getContent())->not->toContain('sk-ant-tajny-klic-uzivatele')
        ->and($response->json('props'))->not->toHaveKey('api_key');
});

it('update uloží klíč přihlášenému uživateli, v DB zašifrovaně', function () {
    $user = $this->actingAsChatUser();

    $this->put('/chat/nastaveni', ['api_key' => 'sk-ant-novy-klic-1234567890'])
        ->assertRedirect(route('chat.settings.show'));

    $settings = UserAiSettings::query()->where('user_id', $user->id)->firstOrFail();

    // Model dešifruje (encrypted cast), surová DB hodnota plaintext být nesmí.
    $raw = (string) DB::table('user_ai_settings')->where('user_id', $user->id)->value('api_key');

    expect($settings->api_key)->toBe('sk-ant-novy-klic-1234567890')
        ->and($raw)->not->toBe('sk-ant-novy-klic-1234567890')
        ->and($raw)->not->toContain('sk-ant-');
});

it('update přepíše existující klíč (updateOrCreate, 1:1 na uživatele)', function () {
    $user = $this->actingAsChatUser();

    UserAiSettings::create(['user_id' => $user->id, 'api_key' => 'sk-ant-stary-klic-1234567890']);

    $this->put('/chat/nastaveni', ['api_key' => 'sk-ant-novy-klic-1234567890']);

    expect(UserAiSettings::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(UserAiSettings::query()->where('user_id', $user->id)->first()?->api_key)
        ->toBe('sk-ant-novy-klic-1234567890');
});

it('update validuje api_key (povinný, min. délka)', function () {
    $this->actingAsChatUser();

    $this->from('/chat/nastaveni')->put('/chat/nastaveni', [])
        ->assertSessionHasErrors('api_key');

    $this->from('/chat/nastaveni')->put('/chat/nastaveni', ['api_key' => 'kratky'])
        ->assertSessionHasErrors('api_key');

    expect(UserAiSettings::query()->count())->toBe(0);
});

it('destroy odstraní klíč, ale zachová záznam s preferred_model', function () {
    $user = $this->actingAsChatUser();

    UserAiSettings::create([
        'user_id' => $user->id,
        'api_key' => 'sk-ant-klic-ke-smazani-123',
        'preferred_model' => 'claude-sonnet-5',
    ]);

    $this->delete('/chat/nastaveni/klic')->assertRedirect(route('chat.settings.show'));

    $settings = UserAiSettings::query()->where('user_id', $user->id)->firstOrFail();

    expect($settings->api_key)->toBeNull()
        ->and($settings->preferred_model)->toBe('claude-sonnet-5');
});

it('destroy bez existujícího záznamu projde (no-op)', function () {
    $this->actingAsChatUser();

    $this->delete('/chat/nastaveni/klic')->assertRedirect(route('chat.settings.show'));
});

it('uživatel bez oprávnění chatu dostane 403 (ChatAuthorizer)', function () {
    $this->actingAsChatUser();

    // Host binding, který chat nikomu nepovolí — settings jedou přes touž bránu.
    app()->bind(
        Webyashopy\Chatbot\Contracts\ChatAuthorizer::class,
        fn (): Webyashopy\Chatbot\Contracts\ChatAuthorizer => new class implements Webyashopy\Chatbot\Contracts\ChatAuthorizer
        {
            public function canUseChat(mixed $user): bool
            {
                return false;
            }

            public function canConfirmAction(mixed $user, string $kind): bool
            {
                return false;
            }
        },
    );

    $this->get('/chat/nastaveni')->assertForbidden();
    $this->put('/chat/nastaveni', ['api_key' => 'sk-ant-nejaky-klic-12345678'])->assertForbidden();
    $this->delete('/chat/nastaveni/klic')->assertForbidden();
});
