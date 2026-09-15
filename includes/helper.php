<?php

if (!function_exists('e')) {
    function e(null|bool|int|float|string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('eBr')) {
    function eBr(null|bool|int|float|string $value): string
    {
        return nl2br(e($value), false); // 사용 (XHTML 필요 없으면 false 권장)
    }
}

if (!function_exists('htmlToPlainText')) {
    function htmlToPlainText(null|bool|int|float|string $value): string
    {
        $text = strip_tags((string) $value);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}

if (!function_exists('formatDatetimeUtcToLocal')) {
    function formatDatetimeUtcToLocal(string $datetimeUtc): string
    {
        $dateUtc = new DateTimeImmutable($datetimeUtc, new DateTimeZone('UTC'));
        $dateKst = $dateUtc->setTimezone(new DateTimeZone('Asia/Seoul'));
        return $dateKst->format('Y-m-d H:i');
    }
}

if (!function_exists('formatDatetime')) {
    function formatDatetime(string $dateTime): string
    {

        $date = explode(" ", $dateTime);
        return $date[0];
    }
}