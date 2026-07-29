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
     * Digitalizace dokumentu — extrakce strukturovaných dat z PDF/obrázku
     * ({@see \Webyashopy\Chatbot\Services\DocumentExtractor}).
     *
     * Vlastní účel, ne sdílený s 'chat': jedno volání nad vícestránkovým PDF
     * spotřebuje řádově víc tokenů než zpráva v chatu, takže by ukecaná
     * digitalizace jinak vyčerpala limit chatu (a naopak).
     *
     * Doménový účel 'ocr' z host aplikací zůstává nedotčený — kdo si ho
     * posílá sám, posílá ho dál.
     */
    public const DOCUMENT = 'document';

    /**
     * Třída je jen jmenný prostor pro konstanty — instance nedávají smysl.
     */
    private function __construct() {}
}
