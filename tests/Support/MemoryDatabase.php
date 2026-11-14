<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Support;

/**
 * The shared state behind one or more MemoryStorage connections: tables, a
 * clock of its own, and switches to simulate an outage or missing tables.
 *
 * Each storage operation runs to completion before another starts (PHP is
 * single threaded), so every statement is atomic, as a single SQL statement
 * is on InnoDB. Two MemoryStorage objects over one database model two
 * processes with two connections.
 */
final class MemoryDatabase
{
    /** @var array<string, array<string, int|string|null>> */
    public array $rules = [];

    /** @var array<int, array<string, int|string|null>> */
    public array $runs = [];

    /** @var array<string, array<string, int|string>> */
    public array $locks = [];

    /** @var array<int, array<string, int|string>> */
    public array $ticks = [];

    public int $runSeq = 0;
    public int $tickSeq = 0;
    public bool $down = false;
    public bool $tablesMissing = false;
    public int $installs = 0;
    public bool $installFails = false;
    public int $queries = 0;

    public function __construct(private TestClock $clock, private int $base = 1_800_000_000)
    {
    }

    public function now(): int
    {
        return $this->base + (int) floor($this->clock->monotonicMs() / 1000);
    }

    public function setNow(int $now): void
    {
        $this->base = $now - (int) floor($this->clock->monotonicMs() / 1000);
    }

    public function install(): bool
    {
        $this->installs++;
        if ($this->installFails) {
            return false;
        }
        $this->tablesMissing = false;
        return true;
    }
}
