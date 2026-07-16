<?php

declare(strict_types=1);

/*
 * Webové routy balíčku (načítá ChatbotServiceProvider přes `hasRoute('web')`).
 *
 * Zatím prázdné — ChatController a routy `chat.*` přijdou v TASK-094.
 * Soubor musí existovat, jinak `hasRoute('web')` spadne na chybějící cestu.
 *
 * Routy se budou registrovat pod prefixem/middleware/`as` z configu
 * (`chatbot.routes.*`), array syntaxí (`[ChatController::class, 'index']`) —
 * to umožní hostovi přepsat controller přes IoC bez patche balíčku.
 */
