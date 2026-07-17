<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Webyashopy\Chatbot\Models\AiUsageLog;
use Webyashopy\Chatbot\Models\UserAiSettings;
use Webyashopy\Chatbot\Tests\Stubs\User;
use Webyashopy\Chatbot\Tests\TestCase;

/**
 * Modely a migrace AI vrstvy (ADR-015 → ADR-019/TASK-091).
 *
 * Kryje riziko K3 (idempotence migrací nad existující tabulkou s produkčními
 * daty) a invariant ADR-017 §7 (`api_key` nikdy v DB plaintextem).
 */
class AiModelsAndMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrace_zalozi_ocekavane_schema(): void
    {
        $this->assertTrue(Schema::hasTable('ai_usage_logs'));
        $this->assertTrue(Schema::hasTable('user_ai_settings'));

        $this->assertTrue(Schema::hasColumns('ai_usage_logs', [
            'id', 'user_id', 'model', 'purpose', 'input_tokens', 'output_tokens',
            'cost', 'key_source', 'success', 'error', 'conversation_id', 'context',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('user_ai_settings', [
            'id', 'user_id', 'api_key', 'preferred_model', 'created_at', 'updated_at',
        ]));
    }

    /**
     * RIZIKO K3 — v hostovi (JNS) tabulky UŽ existují s produkčními daty.
     * Druhý běh migrace nad existující tabulkou musí být no-op bez chyby
     * a NESMÍ sáhnout na data.
     */
    public function test_migrace_je_idempotentni_nad_existujici_tabulkou(): void
    {
        // Simuluje produkční řádek, který v hostovi už v tabulce je.
        AiUsageLog::create([
            'user_id' => null,
            'model' => 'claude-haiku-4-5',
            'purpose' => 'ocr',
            'input_tokens' => 1,
            'output_tokens' => 2,
            'cost' => 0.5,
            'key_source' => 'env',
            'success' => true,
        ]);

        $migrations = [
            __DIR__.'/../../../database/migrations/2026_07_16_100000_create_ai_usage_logs_table.php',
            __DIR__.'/../../../database/migrations/2026_07_16_100100_create_user_ai_settings_table.php',
        ];

        foreach ($migrations as $path) {
            $migration = require $path;

            // Druhý běh nad existující tabulkou — žádná výjimka, žádná změna.
            $migration->up();
        }

        $this->assertSame(1, AiUsageLog::query()->count(), 'Idempotentní migrace nesmí přijít o data.');
        $this->assertTrue(Schema::hasTable('ai_usage_logs'));
        $this->assertTrue(Schema::hasTable('user_ai_settings'));
    }

    /**
     * ADR-017 §7 — `api_key` je `encrypted` cast; v DB nikdy plaintext.
     */
    public function test_api_key_je_v_databazi_zasifrovany(): void
    {
        $user = $this->createUser();

        UserAiSettings::create([
            'user_id' => $user->id,
            'api_key' => 'sk-ant-tajny-klic',
            'preferred_model' => 'claude-haiku-4-5',
        ]);

        $raw = (string) DB::table('user_ai_settings')->where('user_id', $user->id)->value('api_key');

        $this->assertNotSame('sk-ant-tajny-klic', $raw);
        $this->assertStringNotContainsString('sk-ant-tajny-klic', $raw);
        $this->assertNotEmpty($raw);

        // Přes model se čte dešifrovaně.
        $this->assertSame('sk-ant-tajny-klic', UserAiSettings::sole()->api_key);
    }

    public function test_purpose_je_volny_string_bez_enumu(): void
    {
        foreach (['chat', 'ocr', 'cokoliv-hostova-domena'] as $purpose) {
            AiUsageLog::create([
                'model' => 'claude-haiku-4-5',
                'purpose' => $purpose,
                'key_source' => 'env',
                'success' => true,
            ]);
        }

        $this->assertSame(
            ['chat', 'cokoliv-hostova-domena', 'ocr'],
            AiUsageLog::query()->orderBy('purpose')->pluck('purpose')->all(),
        );
    }

    public function test_log_je_append_only(): void
    {
        $log = AiUsageLog::create([
            'model' => 'claude-haiku-4-5',
            'purpose' => 'chat',
            'key_source' => 'env',
            'success' => true,
        ]);

        $this->expectException(LogicException::class);
        $log->update(['model' => 'jiny-model']);
    }

    public function test_log_nelze_smazat(): void
    {
        $log = AiUsageLog::create([
            'model' => 'claude-haiku-4-5',
            'purpose' => 'chat',
            'key_source' => 'env',
            'success' => true,
        ]);

        $this->expectException(LogicException::class);
        $log->delete();
    }

    /**
     * TASK-PT-006-fix-1 — `api_key` se nesmí dostat do serializace modelu
     * (toArray/toJson), i když je relace `user->aiSettings` eager-loaded
     * a instance uživatele je serializovaná hostem (např. do Inertia props).
     */
    public function test_api_key_neni_v_serializaci_modelu(): void
    {
        $user = $this->createUser();

        $settings = UserAiSettings::create([
            'user_id' => $user->id,
            'api_key' => 'sk-ant-tajny-klic',
            'preferred_model' => 'claude-haiku-4-5',
        ]);

        $array = $settings->toArray();
        $json = $settings->toJson();

        $this->assertArrayNotHasKey('api_key', $array);
        $this->assertStringNotContainsString('api_key', $json);
        $this->assertStringNotContainsString('sk-ant-tajny-klic', $json);
    }

    /**
     * Balíček host User model neimportuje — relace se staví z configu.
     */
    public function test_relace_na_usera_se_bere_z_configu(): void
    {
        // Host si User model určuje configem — balíček ho nikdy neimportuje.
        config(['chatbot.user_model' => User::class]);

        $user = $this->createUser();

        $log = AiUsageLog::create([
            'user_id' => $user->id,
            'model' => 'claude-haiku-4-5',
            'purpose' => 'chat',
            'key_source' => 'env',
            'success' => true,
        ]);

        $settings = UserAiSettings::create(['user_id' => $user->id, 'api_key' => 'k']);

        $this->assertInstanceOf(User::class, $log->user);
        $this->assertSame($user->id, $log->user->id);
        $this->assertInstanceOf(User::class, $settings->user);
    }
}
