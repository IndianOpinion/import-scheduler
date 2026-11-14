<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Support;

use IndianOpinion\ImportScheduler\Storage\Connection;

/**
 * Records every statement. Answers the clock query, reports one affected
 * row for writes and empty results otherwise, unless a canned answer is set.
 */
final class RecordingConnection implements Connection
{
    /** @var list<array{0: string, 1: list<int|string|null>}> */
    public array $statements = [];

    /** @var array<string, list<array<string, mixed>>> Regex => rows. */
    public array $answers = [];

    public function __construct(private string $prefix = 'wp_', public int $now = 1_800_000_000)
    {
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->statements[] = [$sql, $params];
        return 1;
    }

    public function select(string $sql, array $params = []): array
    {
        $this->statements[] = [$sql, $params];
        if (str_starts_with($sql, 'SELECT UNIX_TIMESTAMP()')) {
            return [['now' => (string) $this->now]];
        }
        foreach ($this->answers as $pattern => $rows) {
            if (preg_match($pattern, $sql) === 1) {
                return $rows;
            }
        }
        return [];
    }

    public function lastInsertId(): int
    {
        return 7;
    }

    /** @return list<string> */
    public function sql(): array
    {
        return array_map(static fn (array $s): string => $s[0], $this->statements);
    }
}
