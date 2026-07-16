<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests;

/**
 * TestCase s VYPNUTOU chat feature (`chatbot.features.chat = false`, ADR-019 §9).
 *
 * Feature flag se vyhodnocuje při bootu provideru (registrace rout, policy,
 * rate limiteru), takže ho nejde přepnout `config()` uvnitř testu — musí být
 * nastavený dřív, než aplikace nabootuje. Proto samostatný TestCase.
 */
abstract class ChatDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('chatbot.features.chat', false);
    }
}
