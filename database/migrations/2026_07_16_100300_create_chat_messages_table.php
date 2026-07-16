<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zprávy konverzace chatbota (ADR-016) — append-only historie. Odpověď
 * asistenta se váže na náklad volání přes `ai_usage_log_id`.
 *
 * Sloučeny sem i sloupce, které JNS přidával samostatnými migracemi
 * (`action` — ADR-017 §4 / TASK-075, `steps` — TASK-076): guard
 * `Schema::hasTable()` vrací na existující tabulce ihned, takže samostatné
 * `Schema::table()` migrace by se v čisté instalaci nikdy nespustily a
 * tabulka by oba sloupce postrádala. V JNS tabulka i oba sloupce existují
 * → celá migrace je no-op (ADR-019 §8, riziko K3).
 *
 * `json` je kompatibilní s PostgreSQL (produkce) i SQLite (testy).
 * Tvar obou sloupců je smluvní — viz `contracts/api/chatbot-tools.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_messages')) {
            return;
        }

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('role');                 // ChatRole: user / assistant
            $table->text('content');
            $table->string('model')->nullable();    // model u odpovědi asistenta
            $table->foreignId('ai_usage_log_id')->nullable()->constrained('ai_usage_logs')->nullOnDelete();
            $table->json('action')->nullable();     // návrh zápisu (proposal)
            $table->json('steps')->nullable();      // průběh tool-use smyčky
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
