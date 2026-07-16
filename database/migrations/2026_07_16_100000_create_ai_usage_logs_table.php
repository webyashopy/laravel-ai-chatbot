<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log AI/API dotazů (ADR-015) — append-only, jeden řádek na každé volání
 * Anthropic API. Zdroj nákladovosti po uživatelích.
 *
 * Idempotentní guard `Schema::hasTable()` (ADR-019 §8, riziko K3) — v host
 * aplikaci (JNS) tabulka UŽ existuje s produkčními daty z vlastní migrace;
 * balíčková migrace tam musí proběhnout jako no-op, ne zakládat znovu.
 *
 * Schéma musí PŘESNĚ odpovídat dnešnímu JNS
 * (`2026_07_15_180100_create_ai_usage_logs_table.php`) — jediná odchylka je
 * FK na `chat_conversations`: `conversation_id` se zakládá jako generický
 * nullable identifikátor a FK se tu doplní jen tehdy, když cílová tabulka
 * už existuje (vzor `tenant_id` v ticketing balíčku).
 *
 * POZOR: v ČISTÉ instalaci ta podmínka NIKDY neplatí — `chat_conversations`
 * vzniká až pozdější migrací (`ai_usage_logs` musí být dřív, `chat_messages`
 * na ni má FK). Podmínka tu proto pokrývá jen hosta, který si obě tabulky
 * přinesl sám. FK v čisté instalaci doplňuje samostatná migrace
 * `2026_07_16_100400_add_conversation_foreign_key_to_ai_usage_logs_table`
 * (nález verify TASK-091) — když tuhle podmínku měníš, sáhni i tam.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage_logs')) {
            return;
        }

        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('model');
            $table->string('purpose'); // volný string (ADR-019 §3): chat / ocr / …
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->decimal('cost', 12, 4)->nullable(); // CZK; null u neznámého modelu
            $table->string('key_source'); // user / env
            $table->boolean('success')->default(true);
            $table->string('error')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('created_at');
            $table->index('purpose');
        });

        // FK na konverzace jen když už tabulka existuje (chat feature / host).
        if (Schema::hasTable('chat_conversations')) {
            Schema::table('ai_usage_logs', function (Blueprint $table): void {
                $table->foreign('conversation_id')
                    ->references('id')->on('chat_conversations')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
