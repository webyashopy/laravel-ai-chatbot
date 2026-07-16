<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Týdenní cron příkaz (TASK-101): porovná nakonfigurované modely
 * (`chatbot.models` + `chatbot.chat.tools.capable_models`) proti reálně
 * dostupným modelům z Anthropic Models API (`GET /v1/models`) a VAROVÁNÍM
 * nahlásí rozdíl — model, který z API zmizel (retired/přejmenovaný), nebo
 * model uvedený jako tool-capable, ale chybějící v allowlistu `chatbot.models`.
 *
 * NEPŘEPÍNÁ nic automaticky (rozhodnutí uživatele 2026-07-16) — floating
 * alias „nejnovější Opus" neexistuje a auto-upgrade mezi generacemi je
 * breaking (Opus 4.7+ odmítá `temperature`/`top_p`/`top_k` i `budget_tokens`
 * → 400). Rozhodnutí, co s nahlášeným modelem udělat, zůstává na člověku.
 *
 * POZOR: Models API cenu modelu (`pricing`) nevrací — `config('chatbot.pricing')`
 * proto tento příkaz nekontroluje a zůstává čistě ruční (viz README.cs.md).
 *
 * Schedule: registrováno v `ChatbotServiceProvider` přes
 * `callAfterResolving(Schedule::class, ...)` — týdně, JEN když je nastavený
 * API klíč (jinak by cron zbytečně volal příkaz, co se sám přeskočí, viz handle()).
 */
class ChatbotModelsCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'chatbot:models-check';

    /**
     * @var string
     */
    protected $description = 'Porovná nakonfigurované AI modely proti reálně dostupným modelům Anthropic API (jen varování, nic nepřepíná)';

    public function handle(): int
    {
        $apiKey = (string) config('chatbot.api.key');

        if ($apiKey === '') {
            $this->info('ANTHROPIC_API_KEY není nastaven, kontrola modelů se přeskakuje.');

            return self::SUCCESS;
        }

        $available = $this->fetchAvailableModels($apiKey);

        if ($available === null) {
            $this->error('Dotaz na Anthropic Models API selhal — detail v logu.');

            return self::FAILURE;
        }

        $models = array_values(array_unique((array) config('chatbot.models', [])));
        $capableModels = array_values(array_unique((array) config('chatbot.chat.tools.capable_models', [])));

        $warnings = 0;

        // Zmizelý model — nakonfigurovaný (allowlist nebo capable_models),
        // ale Anthropic API ho už mezi dostupnými modely nevrací.
        foreach (array_unique([...$models, ...$capableModels]) as $model) {
            if (! in_array($model, $available, true)) {
                $this->warn("Model '{$model}' je nakonfigurovaný, ale Anthropic Models API ho už nevrací (pravděpodobně retired/přejmenovaný).");
                $warnings++;
            }
        }

        // Nekonzistence configu — model schopný tool-use, ale mimo allowlist chatu.
        foreach ($capableModels as $model) {
            if (! in_array($model, $models, true)) {
                $this->warn("Model '{$model}' je v chatbot.chat.tools.capable_models, ale chybí v allowlistu chatbot.models.");
                $warnings++;
            }
        }

        if ($warnings === 0) {
            $this->info('Konfigurace modelů odpovídá reálně dostupným modelům Anthropic API.');
        }

        return self::SUCCESS;
    }

    /**
     * Načte seznam id dostupných modelů z Anthropic Models API (se stránkováním).
     *
     * @return array<int, string>|null Null = volání selhalo (detail v logu).
     */
    protected function fetchAvailableModels(string $apiKey): ?array
    {
        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => (string) config('chatbot.api.version', '2023-06-01'),
        ];
        $baseUrl = rtrim((string) config('chatbot.api.url'), '/').'/models';

        $ids = [];
        $afterId = null;

        do {
            try {
                $response = Http::timeout((int) config('chatbot.timeouts.request', 60))
                    ->withHeaders($headers)
                    ->get($baseUrl, $afterId !== null ? ['after_id' => $afterId] : []);
            } catch (Throwable $e) {
                Log::error('chatbot:models-check: volání Models API selhalo', ['error' => $e->getMessage()]);

                return null;
            }

            if (! $response->successful()) {
                Log::error('chatbot:models-check: Models API vrátilo chybu', ['status' => $response->status()]);

                return null;
            }

            /** @var array<int, array<string, mixed>> $data */
            $data = $response->json('data', []);

            foreach ($data as $model) {
                $ids[] = (string) ($model['id'] ?? '');
            }

            $hasMore = (bool) $response->json('has_more', false);
            $afterId = $hasMore ? $response->json('last_id') : null;
        } while ($hasMore && $afterId !== null);

        return array_values(array_filter($ids, static fn (string $id): bool => $id !== ''));
    }
}
