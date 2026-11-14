<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Cli;

/**
 * Where command output goes. WpCliIo in production; a buffer in tests.
 */
interface Io
{
    /** Always printed. */
    public function line(string $text): void;

    /** Printed unless --quiet. */
    public function log(string $text): void;

    public function warning(string $text): void;

    /** Printed to stderr; does not exit. */
    public function error(string $text): void;

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $fields
     */
    public function table(array $rows, array $fields, string $format): void;

    /** Exit with this status (0 returns normally). */
    public function halt(int $code): void;
}
