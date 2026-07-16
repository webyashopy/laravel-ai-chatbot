<?php

declare(strict_types=1);

use Webyashopy\Chatbot\Tests\ChatDisabledTestCase;
use Webyashopy\Chatbot\Tests\TestCase;

// Všechny testy ve složce Feature/ běží nad balíčkovým TestCase (Testbench).
uses(TestCase::class)->in('Feature');

// Testy s vypnutou feature `chatbot.features.chat` mají VLASTNÍ složku:
// feature flag se vyhodnocuje při bootu provideru, takže potřebuje jiný
// TestCase — a Pest dovolí namapovat jen jeden TestCase na složku.
uses(ChatDisabledTestCase::class)->in('ChatDisabled');
