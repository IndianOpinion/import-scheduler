<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Small string helpers that respect byte limits without breaking UTF-8.
 */
final class Text
{
    /**
     * Keep at most $bytes bytes from the start, never splitting a character.
     */
    public static function head(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }
        $cut = substr($text, 0, max(0, $bytes));
        for ($i = 0; $i < 3 && $cut !== '' && preg_match('//u', $cut) !== 1; $i++) {
            $cut = substr($cut, 0, -1);
        }
        return $cut;
    }

    /**
     * Keep at most $bytes bytes from the end, never splitting a character.
     */
    public static function tail(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }
        $cut = substr($text, -max(1, $bytes));
        for ($i = 0; $i < 3 && $cut !== '' && preg_match('//u', $cut) !== 1; $i++) {
            $cut = substr($cut, 1);
        }
        return $cut;
    }

    /**
     * One line, collapsed whitespace.
     */
    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
