<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Stubs\Tools;

use Webyashopy\Chatbot\Contracts\ChatTool;

/**
 * Write nástroj hosta — VŽDY jen vrátí návrh (`status => proposal`), sám nikdy
 * nic nezapisuje (ADR-017 §4, human-in-the-loop). Zápis proběhne až po
 * potvrzení uživatelem, v ChatActionHandleru hosta.
 */
class ProposeStubTool implements ChatTool
{
    public function name(): string
    {
        return 'propose_zaznam';
    }

    public function definition(): array
    {
        return [
            'name' => 'propose_zaznam',
            'description' => 'Navrhne založení záznamu.',
            'input_schema' => ['type' => 'object'],
        ];
    }

    public function handle(array $input, mixed $user): array
    {
        return [
            'status' => 'proposal',
            'kind' => 'test_zaznam',
            'payload' => ['ico' => '12345678'],
            'summary' => 'Návrh záznamu čeká na potvrzení.',
        ];
    }
}
