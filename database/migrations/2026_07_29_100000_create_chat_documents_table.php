<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nahrané dokumenty k digitalizaci (PDF / obrázky).
 *
 * Idempotentní guard `Schema::hasTable()` (ADR-019 §8) — v hostovi, kde
 * tabulka už existuje, proběhne migrace jako no-op.
 *
 * POZOR: tabulka se zakládá BEZ ohledu na `chatbot.features.documents`.
 * Schéma DB nesmí záviset na runtime přepínači — jinak by pozdější zapnutí
 * feature v už zmigrované DB tabulky nikdy nedoplnilo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_documents')) {
            return;
        }

        Schema::create('chat_documents', function (Blueprint $table): void {
            $table->id();

            // Nullable: dokument smí zpracovat i systémová úloha bez uživatele
            // (import ze sdílené schránky, cron). Cascade proto, aby smazání
            // uživatele odklidilo i jeho nahrané doklady (GDPR).
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('disk');                     // z `chatbot.documents.disk` v době uložení
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);           // určeno z OBSAHU (finfo), ne z přípony
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->unsignedInteger('pages')->nullable(); // null = u PDF nešlo zjistit, u obrázků vždy

            $table->timestamps();

            // Deduplikace nahrávek: stejný soubor od stejného uživatele se
            // neukládá dvakrát. NE unique — dva uživatelé smějí mít tentýž
            // dokument každý zvlášť (a smazání jednoho nesmí vzít druhému).
            $table->index(['user_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_documents');
    }
};
