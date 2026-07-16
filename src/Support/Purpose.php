<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Support;

/**
 * Účely volání AI (`ai_usage_logs.purpose`).
 *
 * `purpose` je záměrně volný string, ne enum (ADR-019 §3) — host si
 * doplní vlastní účely (JNS: 'ocr') bez zásahu do balíčku. Balíček
 * definuje jen ty svoje.
 *
 * Rate limit se pro daný účel hledá v `chatbot.rate.per_purpose.{purpose}`
 * s fallbackem na `chatbot.rate.default`.
 */
final class Purpose
{
    /** Konverzace v chatu (agentní smyčka i prosté complete()). */
    public const CHAT = 'chat';

    /**
     * Třída je jen jmenný prostor pro konstanty — instance nedávají smysl.
     */
    private function __construct() {}
}
