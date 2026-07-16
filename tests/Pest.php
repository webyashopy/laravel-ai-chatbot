<?php

declare(strict_types=1);

use Webyashopy\Chatbot\Tests\TestCase;

// Všechny testy ve složce Feature/ běží nad balíčkovým TestCase (Testbench).
uses(TestCase::class)->in('Feature');
