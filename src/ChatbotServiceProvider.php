<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Webyashopy\Chatbot\Contracts\ChatAuthorizer;
use Webyashopy\Chatbot\Support\AllowAuthenticatedChatAuthorizer;

/**
 * Service provider balíčku webyashopy/laravel-ai-chatbot.
 *
 * Odpovědnosti:
 *
 * Config `config/chatbot.php` (`hasConfigFile`) — publikovatelný do hosta.
 *
 * Migrace balíčku (`discoversMigrations` + `runsMigrations`) — zatím žádné,
 * přijdou v TASK-094 (chat_conversations, chat_messages, ai_usage_logs,
 * user_ai_settings; každá idempotentní přes `Schema::hasTable`).
 *
 * Routy (`hasRoute('web')` → `routes/web.php`) — zatím prázdné, controller
 * a routy přijdou v TASK-094.
 *
 * Bindingy kontraktů na výchozí implementace — host je přepíše vlastním
 * bindingem ve svém provideru.
 */
class ChatbotServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-ai-chatbot')
            ->hasConfigFile('chatbot')
            ->discoversMigrations()
            ->runsMigrations()
            ->hasRoute('web');
    }

    /**
     * Hook Spatie Package Tools — volá se uvnitř fáze `register()`.
     *
     * Bindujeme přes `bind()` (ne `singleton()`) — kontrakty jsou stateless
     * a `bind()` nechá host aplikaci snadno přepsat default vlastní třídou.
     */
    public function packageRegistered(): void
    {
        $this->app->bind(ChatAuthorizer::class, AllowAuthenticatedChatAuthorizer::class);
    }
}
