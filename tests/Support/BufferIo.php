<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Support;

use IndianOpinion\ImportScheduler\Cli\Io;

/**
 * Captures command output. Halt records the exit status instead of exiting.
 */
final class BufferIo implements Io
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    public ?int $exit = null;

    public function __construct(public bool $quiet = false)
    {
    }

    public function line(string $text): void
    {
        $this->lines[] = $text;
    }

    public function log(string $text): void
    {
        if (!$this->quiet) {
            $this->lines[] = $text;
        }
    }

    public function warning(string $text): void
    {
        $this->warnings[] = $text;
    }

    public function error(string $text): void
    {
        $this->errors[] = $text;
    }

    public function table(array $rows, array $fields, string $format): void
    {
        $picked = array_map(static function (array $row) use ($fields): array {
            $out = [];
            foreach ($fields as $field) {
                $out[$field] = $row[$field] ?? '';
            }
            return $out;
        }, $rows);
        switch ($format) {
            case 'json':
                $this->lines[] = (string) json_encode($picked);
                return;
            case 'csv':
                $this->lines[] = implode(',', $fields);
                foreach ($picked as $row) {
                    $this->lines[] = implode(',', array_map(static fn ($v): string => '"' . str_replace('"', '""', (string) $v) . '"', $row));
                }
                return;
            case 'yaml':
                foreach ($picked as $row) {
                    $first = true;
                    foreach ($row as $k => $v) {
                        $this->lines[] = ($first ? '- ' : '  ') . $k . ': ' . (string) $v;
                        $first = false;
                    }
                }
                return;
            default:
                $this->lines[] = implode("\t", $fields);
                foreach ($picked as $row) {
                    $this->lines[] = implode("\t", array_map('strval', $row));
                }
        }
    }

    public function halt(int $code): void
    {
        if ($this->exit === null || $code !== 0) {
            $this->exit = $code;
        }
    }

    /** The exit status, 0 when nothing halted. */
    public function code(): int
    {
        return $this->exit ?? 0;
    }

    public function text(): string
    {
        return implode("\n", array_merge($this->lines, $this->warnings, $this->errors));
    }
}
