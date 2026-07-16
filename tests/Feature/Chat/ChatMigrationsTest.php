<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * Migrace chatu — schéma čisté instalace a FK `ai_usage_logs.conversation_id`
 * (nález verify TASK-091).
 *
 * Testbench = ČISTÁ instalace balíčku (žádné tabulky hosta), takže přesně
 * ten scénář, ve kterém FK dosud nikdy nevznikl.
 */

uses(RefreshDatabase::class);

/**
 * FK nad `ai_usage_logs.conversation_id`, nebo `null`.
 *
 * @return array<string, mixed>|null
 */
function conversationForeignKey(): ?array
{
    foreach (Schema::getForeignKeys('ai_usage_logs') as $foreignKey) {
        if (array_map('strtolower', (array) $foreignKey['columns']) === ['conversation_id']) {
            return $foreignKey;
        }
    }

    return null;
}

it('čistá instalace vytvoří tabulky chatu se smluvními sloupci', function () {
    expect(Schema::hasTable('chat_conversations'))->toBeTrue()
        ->and(Schema::hasColumns('chat_conversations', ['id', 'user_id', 'title', 'model', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasTable('chat_messages'))->toBeTrue()
        // `action` a `steps` přidával JNS zvlášť — v čisté instalaci musí vzniknout
        // rovnou s tabulkou, jinak by je guard `hasTable` přeskočil.
        ->and(Schema::hasColumns('chat_messages', [
            'id', 'conversation_id', 'role', 'content', 'model', 'ai_usage_log_id', 'action', 'steps',
        ]))->toBeTrue();
});

it('čistá instalace doplní FK ai_usage_logs.conversation_id → chat_conversations', function () {
    // NÁLEZ verify TASK-091: migrace `create_ai_usage_logs_table` zakládá FK jen
    // když `chat_conversations` UŽ existuje — což v čisté instalaci nikdy neplatilo,
    // takže FK nevznikl. Doplňuje ho samostatná migrace až po obou tabulkách.
    $foreignKey = conversationForeignKey();

    expect($foreignKey)->not->toBeNull()
        ->and(strtolower((string) $foreignKey['foreign_table']))->toBe('chat_conversations')
        ->and(array_map('strtolower', (array) $foreignKey['foreign_columns']))->toBe(['id']);
});

it('doplnění FK je idempotentní — druhý běh migrace nic nerozbije', function () {
    // V JNS FK už drží z vlastní migrace, takže balíčková musí být no-op.
    // Ověřujeme přes opakované spuštění: FK zůstane právě jeden.
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

    $matching = array_filter(
        Schema::getForeignKeys('ai_usage_logs'),
        static fn (array $fk): bool => array_map('strtolower', (array) $fk['columns']) === ['conversation_id'],
    );

    expect($matching)->toHaveCount(1);
});
