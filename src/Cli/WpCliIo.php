<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Cli;

/**
 * Io over WP-CLI. WP_CLI::log() honours the global --quiet flag, so run
 * lines disappear under --quiet while failures and the summary stay.
 */
final class WpCliIo implements Io
{
    public function line(string $text): void
    {
        \WP_CLI::line($text);
    }

    public function log(string $text): void
    {
        \WP_CLI::log($text);
    }

    public function warning(string $text): void
    {
        \WP_CLI::warning($text);
    }

    public function error(string $text): void
    {
        \WP_CLI::error($text, false);
    }

    public function table(array $rows, array $fields, string $format): void
    {
        \WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    public function halt(int $code): void
    {
        if ($code !== 0) {
            \WP_CLI::halt($code);
        }
    }
}
