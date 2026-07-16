<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Webyashopy\Chatbot\Tests\TestCase;

/**
 * Testy `chatbot:models-check` (TASK-101) — fake HTTP odpověď Models API,
 * žádné reálné volání Anthropic.
 */
class ChatbotModelsCheckCommandTest extends TestCase
{
    public function test_bez_api_klice_se_prikaz_preskoci_a_nevola_api(): void
    {
        config(['chatbot.api.key' => '']);

        $this->artisan('chatbot:models-check')
            ->expectsOutputToContain('ANTHROPIC_API_KEY není nastaven')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_nahlasi_model_ktery_zmizel_z_anthropic_api(): void
    {
        config([
            'chatbot.api.key' => 'sk-ant-test',
            'chatbot.models' => ['claude-sonnet-5', 'claude-haiku-4-5'],
            'chatbot.chat.tools.capable_models' => ['claude-sonnet-5'],
        ]);

        Http::fake([
            '*/models*' => Http::response([
                // haiku chybí — API ho už nevrací (retired/přejmenovaný).
                'data' => [
                    ['id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 4.5'],
                ],
                'has_more' => false,
            ], 200),
        ]);

        $this->artisan('chatbot:models-check')
            ->expectsOutputToContain("Model 'claude-haiku-4-5' je nakonfigurovaný, ale Anthropic Models API ho už nevrací")
            ->assertExitCode(0);
    }

    public function test_nahlasi_capable_model_ktery_chybi_v_allowlistu(): void
    {
        config([
            'chatbot.api.key' => 'sk-ant-test',
            'chatbot.models' => ['claude-sonnet-5'],
            'chatbot.chat.tools.capable_models' => ['claude-sonnet-5', 'claude-opus-4-8'],
        ]);

        Http::fake([
            '*/models*' => Http::response([
                'data' => [
                    ['id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 4.5'],
                    ['id' => 'claude-opus-4-8', 'display_name' => 'Claude Opus 4.1'],
                ],
                'has_more' => false,
            ], 200),
        ]);

        $this->artisan('chatbot:models-check')
            ->expectsOutputToContain("Model 'claude-opus-4-8' je v chatbot.chat.tools.capable_models, ale chybí v allowlistu chatbot.models")
            ->assertExitCode(0);
    }

    public function test_pri_shode_nahlasi_v_poradku(): void
    {
        config([
            'chatbot.api.key' => 'sk-ant-test',
            'chatbot.models' => ['claude-sonnet-5'],
            'chatbot.chat.tools.capable_models' => ['claude-sonnet-5'],
        ]);

        Http::fake([
            '*/models*' => Http::response([
                'data' => [
                    ['id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 4.5'],
                ],
                'has_more' => false,
            ], 200),
        ]);

        $this->artisan('chatbot:models-check')
            ->expectsOutputToContain('Konfigurace modelů odpovídá reálně dostupným modelům Anthropic API.')
            ->assertExitCode(0);
    }

    public function test_selhani_api_vraci_failure_a_loguje(): void
    {
        config(['chatbot.api.key' => 'sk-ant-test']);

        Http::fake([
            '*/models*' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $this->artisan('chatbot:models-check')
            ->assertExitCode(1);
    }

    public function test_stankovani_projde_vice_stranek(): void
    {
        config([
            'chatbot.api.key' => 'sk-ant-test',
            'chatbot.models' => ['model-a', 'model-b'],
            'chatbot.chat.tools.capable_models' => [],
        ]);

        Http::fake([
            '*/models*' => function ($request) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $afterId = $query['after_id'] ?? null;

                if ($afterId === null) {
                    return Http::response([
                        'data' => [['id' => 'model-a']],
                        'has_more' => true,
                        'last_id' => 'model-a',
                    ], 200);
                }

                return Http::response([
                    'data' => [['id' => 'model-b']],
                    'has_more' => false,
                ], 200);
            },
        ]);

        $this->artisan('chatbot:models-check')
            ->expectsOutputToContain('Konfigurace modelů odpovídá reálně dostupným modelům Anthropic API.')
            ->assertExitCode(0);
    }
}
