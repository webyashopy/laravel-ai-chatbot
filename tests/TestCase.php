<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Webyashopy\Chatbot\ChatbotServiceProvider;

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
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
