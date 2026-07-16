<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Webyashopy\Chatbot\Models\AiUsageLog;
use Webyashopy\Chatbot\Services\AiService;
use Webyashopy\Chatbot\Support\Purpose;
use Webyashopy\Chatbot\Tests\TestCase;

/**
 * PŘÍMÝ TEST RIZIKA K2 (ADR-019) — balíček nesmí číst config host aplikace.
 *
 * V JNS četl `AiService` `config('ocr.*')`, `config('ai.*')` a
 * `config('services.anthropic.*')`. Testbench žádný z těchto configů nemá
 * (žádné `config/ocr.php`, žádná sekce `services.anthropic`) — pokud tedy
 * volání projde a zaloguje se korektně JEN z `config('chatbot.*')`, je vazba
 * na hosta prokazatelně přeťatá.
 */
class AiServiceHostConfigIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hostovy_configy_v_testbenchi_vubec_neexistuji(): void
    {
        // Předpoklad testu: kdyby je Testbench měl, test níže by nic nedokazoval.
        $this->assertNull(config('ocr'), 'Testbench nesmí mít hostův config/ocr.php.');
        $this->assertNull(config('ai'), 'Testbench nesmí mít hostův config/ai.php.');
        $this->assertNull(config('services.anthropic'), 'Testbench nesmí mít services.anthropic.');
    }

    public function test_service_funguje_bez_ocr_configu_a_services_anthropic(): void
    {
        config([
            'chatbot.api.key' => 'env-server-key',
            'chatbot.api.url' => 'https://api.example.test/v1',
            'chatbot.api.version' => '2023-06-01',
            'chatbot.model' => 'claude-haiku-4-5',
        ]);

        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        /** @var AiService $service */
        $service = app(AiService::class);
        $result = $service->complete('ahoj');

        $this->assertSame('ok', $result['content']);

        // URL, verze i klíč pochází výhradně z chatbot.api.*
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.example.test/v1/messages'
            && $request->hasHeader('x-api-key', 'env-server-key')
            && $request->hasHeader('anthropic-version', '2023-06-01'));

        // Model z chatbot.model, purpose default chat, cena z chatbot.pricing.
        $log = AiUsageLog::sole();
        $this->assertSame('claude-haiku-4-5', $log->model);
        $this->assertSame(Purpose::CHAT, $log->purpose);
        $this->assertNotNull($log->cost);
    }

    public function test_ve_zdrojich_baliku_neni_zadny_odkaz_na_config_hosta(): void
    {
        $sources = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src')
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        $this->assertNotEmpty($sources);

        // Zakázané prefixy configu — patří hostovi, ne balíčku (ADR-019, K2).
        $forbidden = ["config('ocr.", 'config("ocr.', "config('ai.", 'config("ai.', 'services.anthropic'];

        foreach ($sources as $path => $code) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    "Soubor {$path} čte config host aplikace ({$needle}) — porušení ADR-019 (K2)."
                );
            }
        }
    }

    public function test_retry_a_timeouty_se_ctou_z_chatbot_configu(): void
    {
        config([
            'chatbot.api.key' => 'env-server-key',
            'chatbot.retry.max_attempts' => 2,
            'chatbot.retry.delay_ms' => 1, // ať test nečeká
            'chatbot.retry.multiplier' => 1,
        ]);

        Http::fake([
            '*' => Http::response(['error' => ['type' => 'overloaded_error', 'message' => 'pretizeno']], 529),
        ]);

        /** @var AiService $service */
        $service = app(AiService::class);

        try {
            $service->complete('ahoj');
            $this->fail('Očekávána RuntimeException po vyčerpání pokusů.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('po 2 pokusech', $e->getMessage());
        }

        // Přesně tolik odeslání, kolik říká chatbot.retry.max_attempts.
        Http::assertSentCount(2);
    }
}
