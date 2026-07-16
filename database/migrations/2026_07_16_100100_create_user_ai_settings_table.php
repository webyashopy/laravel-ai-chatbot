<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user AI nastavení (ADR-015) — 1:1 na uživatele.
 * `api_key` je v modelu `encrypted` cast a do FE se nikdy neposílá plaintextem.
 *
 * Idempotentní guard `Schema::hasTable()` (ADR-019 §8, riziko K3) — v JNS
 * tabulka už existuje s produkčními daty, balíčková migrace je tam no-op.
 * Schéma odpovídá `2026_07_15_180400_create_user_ai_settings_table.php` v JNS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_ai_settings')) {
            return;
        }

        Schema::create('user_ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('api_key')->nullable(); // encrypted cast (Models\UserAiSettings)
            $table->string('preferred_model')->nullable(); // z allowlistu config('chatbot.models')
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ai_settings');
    }
};
