<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Webyashopy\Chatbot\Services\AiService;
use Webyashopy\Chatbot\Support\Purpose;
use Webyashopy\Chatbot\Tests\TestCase;

/**
 * Rate limit dle ADR-019 §3 — `purpose` je volný string, limit se hledá
 * v `chatbot.rate.per_purpose.{purpose}` s fallbackem `chatbot.rate.default`.
 * Balíček nezná doménové účely hosta ('ocr' sem chodí jako obyčejný string).
 */
class AiServiceRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['chatbot.api.key' => 'env-server-key']);

        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);
    }

    /**
     * Vyčerpá bucket daného účelu a vrátí zprávu výjimky.
     */
    private function exhaust(string $purpose, int $limit): string
    {
        /** @var AiService $service */
        $service = app(AiService::class);
        $user = $this->createUser();

        for ($i = 0; $i < $limit; $i++) {
            $service->complete('dotaz', null, ['user' => $user, 'purpose' => $purpose]);
        }

        try {
            $service->complete('dotaz navic', null, ['user' => $user, 'purpose' => $purpose]);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail("Očekáván vyčerpaný rate limit pro purpose '{$purpose}'.");
    }

    public function test_purpose_chat_bere_limit_z_per_purpose(): void
    {
        config(['chatbot.rate.per_purpose.chat' => 2, 'chatbot.rate.default' => 99]);

        $this->assertStringContainsString('(2/min)', $this->exhaust(Purpose::CHAT, 2));
    }

    public function test_purpose_ocr_bere_limit_z_per_purpose(): void
    {
        config(['chatbot.rate.per_purpose.ocr' => 3, 'chatbot.rate.default' => 99]);

        $this->assertStringContainsString('(3/min)', $this->exhaust('ocr', 3));
    }

    /**
     * Účel, který v `per_purpose` není (host si zavedl vlastní) → default.
     */
    public function test_neznamy_purpose_spadne_na_default_limit(): void
    {
        config(['chatbot.rate.default' => 2]);

        $this->assertStringContainsString('(2/min)', $this->exhaust('preklad-dokumentu', 2));
    }

    /**
     * Buckety jsou oddělené i mezi uživateli — jeden ukecaný uživatel
     * nevyhladoví ostatní (audit 2026-07-15, M1).
     */
    public function test_bucket_je_oddeleny_per_uzivatel(): void
    {
        config(['chatbot.rate.per_purpose.chat' => 1]);

        /** @var AiService $service */
        $service = app(AiService::class);

        $first = $this->createUser();
        $second = $this->createUser();

        $service->complete('dotaz', null, ['user' => $first, 'purpose' => Purpose::CHAT]);

        try {
            $service->complete('dotaz', null, ['user' => $first, 'purpose' => Purpose::CHAT]);
            $this->fail('Očekáván vyčerpaný limit prvního uživatele.');
        } catch (\RuntimeException) {
            // očekávané
        }

        // Druhý uživatel má vlastní bucket — musí projít.
        $result = $service->complete('dotaz', null, ['user' => $second, 'purpose' => Purpose::CHAT]);
        $this->assertSame('ok', $result['content']);
    }

    /**
     * TASK-103: `chatbot.rate.global_per_purpose` je bucket BEZ userId —
     * vyčerpá se součtem volání NAPŘÍČ uživateli, ne per uživatel.
     * Per-user limit necháme vysoký, aby narazil právě jen globální strop.
     */
    public function test_globalni_strop_je_sdileny_napric_uzivateli(): void
    {
        config([
            'chatbot.rate.per_purpose.ocr' => 99,
            'chatbot.rate.global_per_purpose' => ['ocr' => 2],
        ]);

        /** @var AiService $service */
        $service = app(AiService::class);

        $first = $this->createUser();
        $second = $this->createUser();
        $third = $this->createUser();

        // Dvě volání DVOU RŮZNÝCH uživatelů projdou (globální bucket = 2).
        $service->complete('dotaz', null, ['user' => $first, 'purpose' => 'ocr']);
        $service->complete('dotaz', null, ['user' => $second, 'purpose' => 'ocr']);

        // Třetí uživatel má vlastní per-user bucket prázdný, přesto narazí
        // na vyčerpaný GLOBÁLNÍ bucket — dokazuje, že strop není per-user.
        try {
            $service->complete('dotaz', null, ['user' => $third, 'purpose' => 'ocr']);
            $this->fail('Očekáván vyčerpaný globální limit pro purpose ocr.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('(2/min)', $e->getMessage());
        }
    }

    /**
     * TASK-103: bez `global_per_purpose` (default prázdné pole) se chování
     * NEMĚNÍ — zpětná kompatibilita. Jen per-user bucket rozhoduje.
     */
    public function test_bez_global_per_purpose_se_chovani_nemeni(): void
    {
        config(['chatbot.rate.per_purpose.ocr' => 2]);
        $this->assertSame([], config('chatbot.rate.global_per_purpose'));

        $this->assertStringContainsString('(2/min)', $this->exhaust('ocr', 2));
    }
}
