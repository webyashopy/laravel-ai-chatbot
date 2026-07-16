<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konverzace chatbota (ADR-016) — privátní 1:N na uživatele hosta.
 *
 * Idempotentní guard `Schema::hasTable()` (ADR-019 §8, riziko K3) — v host
 * aplikaci (JNS) tabulka UŽ existuje s produkčními daty z vlastní migrace;
 * balíčková migrace tam proběhne jako no-op, nikdy nezakládá znovu.
 *
 * Schéma musí PŘESNĚ odpovídat dnešnímu JNS
 * (`2026_07_15_180000_create_chat_conversations_table.php`).
 *
 * POZOR: tabulka se zakládá BEZ ohledu na `chatbot.features.chat`. Schéma DB
 * nesmí záviset na runtime přepínači — jinak by zapnutí chatu později
 * v už zmigrované DB tabulky nikdy nedoplnilo (migrace už je „spuštěná").
 * Feature flag vypíná routy/policy, ne migrace.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_conversations')) {
            return;
        }

        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();    // auto z první zprávy
            $table->string('model');                // výchozí model konverzace (allowlist `chatbot.models`)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
