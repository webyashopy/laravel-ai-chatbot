<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Webyashopy\Chatbot\Console\Commands\ChatbotModelsCheckCommand;
use Webyashopy\Chatbot\Contracts\ChatAuthorizer;
use Webyashopy\Chatbot\Models\ChatConversation;
use Webyashopy\Chatbot\Policies\ChatConversationPolicy;
use Webyashopy\Chatbot\Services\ChatActionHandlerRegistry;
use Webyashopy\Chatbot\Services\ChatToolRegistry;
use Webyashopy\Chatbot\Services\DocumentSchemaRegistry;
use Webyashopy\Chatbot\Support\AllowAuthenticatedChatAuthorizer;

/**
 * Service provider balíčku webyashopy/laravel-ai-chatbot.
 *
 * Odpovědnosti:
 *
 * Config `config/chatbot.php` (`hasConfigFile`) — publikovatelný do hosta.
 *
 * Migrace balíčku (`discoversMigrations` + `runsMigrations`) — `ai_usage_logs`,
 * `user_ai_settings`, `chat_conversations`, `chat_messages`, `chat_documents`,
 * `document_extractions` a doplnění FK
 * `ai_usage_logs.conversation_id`. Každá je idempotentní přes `Schema::hasTable`
 * (ADR-019 §8): v hostovi, kde tabulka už existuje s produkčními daty, proběhne
 * jako no-op.
 *
 * Chat vrstva (routy, policy, rate limiter) — jen když je zapnutá feature
 * `chatbot.features.chat` (ADR-019 §9). Vypnutá feature nechává AI vrstvu
 * (AiService, usage logging, per-user klíče) plně funkční.
 *
 * Bindingy kontraktů na výchozí implementace — host je přepíše vlastním
 * bindingem ve svém provideru.
 */
class ChatbotServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        // POZOR: `hasRoute('web')` se ZÁMĚRNĚ nepoužívá — načetlo by routy natvrdo
        // a bez skupiny (prefix/middleware/`as` z configu). Registraci proto dělá
        // `registerChatRoutes()` níže, aby šla i vypnout feature flagem.
        $package
            ->name('laravel-ai-chatbot')
            ->hasConfigFile('chatbot')
            ->discoversMigrations()
            ->runsMigrations()
            ->hasCommands([
                ChatbotModelsCheckCommand::class,
            ]);
    }

    /**
     * Hook Spatie Package Tools — volá se uvnitř fáze `register()`.
     *
     * Kontrakty bindujeme přes `bind()` (ne `singleton()`) — jsou stateless
     * a `bind()` nechá host aplikaci snadno přepsat default vlastní třídou.
     *
     * Registry naopak jako `singleton()`: discovery skenuje disk a instanciuje
     * nalezené třídy, takže per-instance cache dává smysl jen tehdy, když je
     * instance v rámci requestu jedna. Bez singletonu by každé `new ChatToolRegistry`
     * skenovalo znovu.
     */
    public function packageRegistered(): void
    {
        // bindIf, ne bind: default je VOLNĚJŠÍ než typický host binding (pustí každého
        // přihlášeného, včetně potvrzení zápisu). S bind() by při jiném pořadí
        // registrace mohl default přebít hostovu přísnější implementaci a tiše
        // otevřít chat všem. bindIf() to vylučuje. (Nález security auditu TASK-099.)
        $this->app->bindIf(ChatAuthorizer::class, AllowAuthenticatedChatAuthorizer::class);

        $this->app->singleton(ChatToolRegistry::class);
        $this->app->singleton(ChatActionHandlerRegistry::class);
        $this->app->singleton(DocumentSchemaRegistry::class);
    }

    /**
     * Hook Spatie Package Tools — volá se uvnitř fáze `boot()`.
     */
    public function packageBooted(): void
    {
        // POZOR: scheduling `chatbot:models-check` je NEZÁVISLÝ na feature flagu
        // `chatbot.features.chat` — patří k AI vrstvě (models/pricing), ne k chatu
        // samotnému (ADR-019 §9: vypnutý chat nechává AI vrstvu funkční).
        $this->scheduleModelsCheck();

        if (! $this->chatEnabled()) {
            return;
        }

        $this->registerChatRateLimiter();
        $this->registerChatPolicies();
        $this->registerChatRoutes();
    }

    /**
     * Je zapnutá chat feature? Vypnutá = jen AI vrstva (ADR-019 §9).
     *
     * Migrace se NEGATUJÍ — schéma DB nesmí záviset na runtime přepínači,
     * jinak by pozdější zapnutí chatu v už zmigrované DB tabulky nedoplnilo.
     */
    private function chatEnabled(): bool
    {
        return (bool) config('chatbot.features.chat', true);
    }

    /**
     * Rate limiter `chat` pro routy volající Anthropic API.
     *
     * Registruje ho balíček, protože na něj odkazuje `throttle:chat` v jeho
     * routách — host by jinak musel limiter dodat, jinak by routy padaly.
     * Host ho může přepsat vlastním `RateLimiter::for('chat', ...)` ve svém
     * provideru (aplikační providery bootují až po balíčkových, takže vyhraje).
     */
    private function registerChatRateLimiter(): void
    {
        RateLimiter::for('chat', function (Request $request): Limit {
            return Limit::perMinute((int) config('chatbot.rate.per_purpose.chat', 20))
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }

    /**
     * Policy konverzací.
     *
     * Registruje se explicitně: konvenční auto-discovery Laravelu hledá policy
     * v `App\Policies\` hosta, což pro model z `vendor/` nikdy nesedne.
     */
    private function registerChatPolicies(): void
    {
        Gate::policy(ChatConversation::class, ChatConversationPolicy::class);
    }

    /**
     * Routy chatu ve skupině z `config('chatbot.routes.*')`.
     *
     * `as` NEMĚNIT — výsledné názvy `chat.*` drží frontend a Wayfinder hosta
     * (ADR-019 §11).
     */
    private function registerChatRoutes(): void
    {
        Route::group([
            'prefix' => config('chatbot.routes.prefix', 'chat'),
            'middleware' => (array) config('chatbot.routes.middleware', ['web', 'auth']),
            'as' => config('chatbot.routes.as', 'chat.'),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Naplánuje `chatbot:models-check` (TASK-101) do scheduleru host aplikace —
     * týdně, po vzoru `tickets:notify-stale`
     * (`webyashopy/laravel-ticketing-system`).
     *
     * `callAfterResolving(Schedule::class, ...)` — scheduler se nesmí resolvovat
     * eagerly (boot pořadí balíčků), proto se plánování odloží až na okamžik,
     * kdy si host aplikace `Schedule` vyžádá.
     *
     * Podmínka na API klíč: bez klíče by příkaz běžel jen proto, aby se sám
     * hned přeskočil (`handle()` to detekuje) — cron by tak zbytečně volal
     * proces každý týden nadarmo.
     */
    private function scheduleModelsCheck(): void
    {
        if ((string) config('chatbot.api.key', '') === '') {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('chatbot:models-check')
                ->weekly()
                ->withoutOverlapping()
                ->name('chatbot:models-check');
        });
    }
}
