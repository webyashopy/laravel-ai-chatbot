<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `action`/`steps` byly založeny jako `json` (PostgreSQL nativní typ) —
 * ale `encrypted:array` cast (TASK-AIBOT-01g, config `chatbot.encrypt_messages`)
 * ukládá base64 ciphertext STRING, který PostgreSQL do sloupce typu `json`
 * nepřijme (musí to být validní JSON hodnota). Migrace mění oba sloupce na
 * `text` — Eloquent `array`/`encrypted:array` cast funguje nad text sloupcem
 * beze změny (de/serializace řeší aplikační vrstva, ne DB typ).
 *
 * SQLite (testy, Testbench) sloupec `json` odjakživa kompiluje na `text`
 * (SQLite typovou afinitu `json` nezná) — tam je migrace no-op. MySQL má
 * vlastní nativní `json` typ jako Postgres, ale není součástí podporované
 * produkční matice balíčku (T4A běží na Postgres) — záměrně no-op, host na
 * MySQL by potřeboval vlastní migraci.
 *
 * Idempotentní (ADR-019 §8 guard vzor): mění typ jen sloupců, které ještě
 * `text` nejsou — druhý běh (host, kde tabulka/sloupce už `text` jsou, nebo
 * opakovaný `migrate`) je no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_messages')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['action', 'steps'] as $column) {
            if (! Schema::hasColumn('chat_messages', $column) || $this->isAlreadyText($column)) {
                continue;
            }

            DB::statement("ALTER TABLE chat_messages ALTER COLUMN {$column} TYPE text USING {$column}::text");
        }
    }

    public function down(): void
    {
        // Záměrně beze změny zpět na `json` — down() migrace balíčku se
        // v produkci nespouští (ADR-019 idiom) a zpětný převod text→json by
        // se zapnutým `encrypt_messages` selhal (ciphertext není validní JSON).
    }

    private function isAlreadyText(string $column): bool
    {
        $row = DB::selectOne(
            'select data_type from information_schema.columns where table_name = ? and column_name = ?',
            ['chat_messages', $column],
        );

        return $row !== null && strtolower((string) $row->data_type) === 'text';
    }
};
