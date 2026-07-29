<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Výsledky extrakce dat z dokumentů — append-only historie.
 *
 * Ukládají se i NEÚSPĚŠNÉ pokusy (`status = 'failed'` + `error`): volání
 * API proběhlo a zaplatilo se, takže po něm musí zůstat stopa.
 *
 * Idempotentní guard `Schema::hasTable()` (ADR-019 §8).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_extractions')) {
            return;
        }

        Schema::create('document_extractions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_id')->constrained('chat_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('schema', 100);              // DocumentSchema::name()
            $table->json('data')->nullable();           // null u neúspěchu
            $table->string('model');

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 10, 4)->nullable(); // CZK; null u neznámého modelu (ADR-015)

            $table->string('status', 20)->default('success');   // 'success' | 'failed'
            $table->text('error')->nullable();

            $table->timestamps();

            // Nese hledání poslední úspěšné extrakce dvojice (dokument, schéma)
            // — díky němu se opakovaná digitalizace téhož souboru neplatí znovu.
            $table->index(['document_id', 'schema', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
