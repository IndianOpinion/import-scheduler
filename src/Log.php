<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Writes to the PHP error log with a fixed prefix, because cron output is
 * often discarded.
 */
final class Log
{
    public const PREFIX = '[import-scheduler] ';

    /** @var (callable(string): void)|null */
    private static $sink = null;

    /**
     * Replace the destination (tests). Null restores error_log().
     *
     * @param (callable(string): void)|null $sink
     */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
    }

    public static function error(string $message): void
    {
        $line = self::PREFIX . Text::oneLine($message);
        if (self::$sink !== null) {
            (self::$sink)($line);
            return;
        }
        error_log($line);
    }
}
