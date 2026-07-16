<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Webyashopy\Chatbot\ChatbotServiceProvider;
use Webyashopy\Chatbot\Tests\Stubs\User;

abstract class TestCase extends Orchestra
{
    /**
     * Service providery balíčku načtené do testovací aplikace.
     *
     * `Inertia\ServiceProvider` přidáváme explicitně — Testbench
     * auto-discoveruje providery jen z balíčku „under test", ne z jeho
     * runtime závislostí. Bez něj by neexistovala Inertia testing macra
     * pro budoucí feature testy HTTP vrstvy (TASK-094).
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Inertia\ServiceProvider::class,
            ChatbotServiceProvider::class,
        ];
    }

    /**
     * Testovací prostředí — in-memory SQLite.
     *
     * POZOR (riziko K2): schválně se tu NENASTAVUJE `config/ocr.php` ani
     * `services.anthropic.*` — Testbench je nemá a balíček je nesmí
     * potřebovat. Viz tests/Feature/Ai/AiServiceHostConfigIsolationTest.php.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Klíč pro `encrypted` cast (user_ai_settings.api_key) — Testbench
        // aplikace nemá .env, bez klíče by šifrování spadlo.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // POZOR: `chatbot.user_model` se tu SCHVÁLNĚ nepřepisuje na testovací
        // stub — SmokeTest ověřuje výchozí hodnotu, kterou balíček reálně veze.
        // Testy, které relaci na usera potřebují, si config přepíšou samy.

        // Auth provider testovací aplikace — Testbench míří defaultně na
        // `App\Models\User`, který v izolovaném prostředí neexistuje. Chat routy
        // jedou pod middlewarem `auth`, takže guard musí mít platný model.
        $app['config']->set('auth.providers.users.model', User::class);
    }

    /**
     * Migrace pro testovací DB.
     *
     * Balíčkové migrace `ai_usage_logs` / `user_ai_settings` mají FK na tabulku
     * `users`, kterou v reálném projektu dodává host aplikace. V izolovaném
     * Testbench prostředí ji proto vytváříme jako minimální stub PŘED tím,
     * než se spustí balíčkové migrace.
     */
    protected function defineDatabaseMigrations(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamps();
            });
        }
    }

    /**
     * Vytvoří testovacího uživatele (stub host modelu).
     */
    protected function createUser(): User
    {
        return User::create([
            'name' => 'Tester',
            'email' => 'tester'.uniqid().'@example.test',
        ]);
    }

    /**
     * Vytvoří uživatele, nastaví ho jako host User model a přihlásí ho.
     *
     * `chatbot.user_model` je potřeba přepnout na stub, jinak by factory
     * balíčku sahaly na `App\Models\User`, který v Testbench neexistuje.
     */
    protected function actingAsChatUser(): User
    {
        config(['chatbot.user_model' => User::class]);

        $user = $this->createUser();

        $this->actingAs($user);

        return $user;
    }

    /**
     * Hlavičky Inertia requestu.
     *
     * Bez nich by Inertia odpověď renderovala root view (`app.blade.php`),
     * kterou testovací Testbench aplikace nemá. S hlavičkou vrací JSON —
     * a props se dají rovnou aserovat.
     *
     * @return array<string, string>
     */
    protected function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => ''];
    }
}
