<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doplní FK `ai_usage_logs.conversation_id` → `chat_conversations.id`
 * (nález verify TASK-091).
 *
 * PROČ SAMOSTATNÁ MIGRACE: migrace `create_ai_usage_logs_table` zakládá FK
 * jen tehdy, když `chat_conversations` už existuje — jenže tu zakládá až
 * migrace pozdější, takže v ČISTÉ INSTALACI ta podmínka nikdy neplatila
 * a FK nevznikl. Přehození dat migrací by problém neřešilo (`ai_usage_logs`
 * musí vzniknout dřív — `chat_messages` na ni má FK) a spoléhat na pořadí
 * dat je stejně křehké. FK proto doplňujeme až tady, po obou tabulkách.
 *
 * IDEMPOTENCE (ADR-019 §8, riziko K3): v JNS FK už drží z vlastní migrace
 * → guard na existenci FK udělá no-op. Guard se ptá schématu, ne názvu
 * constraintu — host si ho mohl pojmenovat jinak.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Chat je volitelný (`chatbot.features.chat`) a `ai_usage_logs` může chybět
        // v exotickém pořadí migrací hosta — bez obou tabulek není co svazovat.
        if (! Schema::hasTable('ai_usage_logs') || ! Schema::hasTable('chat_conversations')) {
            return;
        }

        if ($this->foreignKeyExists()) {
            return;
        }

        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->foreign('conversation_id')
                ->references('id')->on('chat_conversations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_usage_logs') || ! $this->foreignKeyExists()) {
            return;
        }

        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->dropForeign(['conversation_id']);
        });
    }

    /**
     * Drží už na `ai_usage_logs.conversation_id` nějaký FK?
     *
     * Ptáme se schématu na SLOUPEC, ne na název constraintu — v JNS FK
     * vytvořila `->constrained()`, takže jméno je Laravelí konvence, ale
     * jiný host ho mít stejné nemusí.
     */
    private function foreignKeyExists(): bool
    {
        foreach (Schema::getForeignKeys('ai_usage_logs') as $foreignKey) {
            $columns = array_map('strtolower', (array) ($foreignKey['columns'] ?? []));

            if ($columns === ['conversation_id']) {
                return true;
            }
        }

        return false;
    }
};
