<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Stubs\Tools;

use Webyashopy\Chatbot\Contracts\ChatTool;

/**
 * Read nástroj hosta — vrací data, nikdy nezapisuje.
 *
 * Zastupuje `read_*` nástroje JNS (TASK-095): balíček o jejich obsahu nic neví.
 */
class ReadStubTool implements ChatTool
{
    /** Uživatel, pod jehož identitou byl nástroj zavolán (ADR-017 §5). */
    public static mixed $user = null;

    public function name(): string
    {
        return 'read_zaznamy';
    }

    public function definition(): array
    {
        return [
            'name' => 'read_zaznamy',
            'description' => 'Přečte záznamy.',
            'input_schema' => ['type' => 'object'],
        ];
    }

    public function handle(array $input, mixed $user): array
    {
        self::$user = $user;

        return ['summary' => '2 záznamy nalezeny', 'items' => [['id' => 1], ['id' => 2]]];
    }

    public static function reset(): void
    {
        self::$user = null;
    }
}
