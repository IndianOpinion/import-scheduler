<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Database time without a query per read: the database clock read once,
 * advanced by the monotonic clock. PHP's own wall clock is never used, so a
 * web node with a skewed clock agrees with the others.
 */
final class DbTime
{
    private float $baseMs;

    public function __construct(private int $base, private Clock $clock)
    {
        $this->baseMs = $clock->monotonicMs();
    }

    public function now(): int
    {
        return $this->base + (int) floor($this->elapsedMs() / 1000);
    }

    public function start(): int
    {
        return $this->base;
    }

    public function elapsedMs(): float
    {
        return max(0.0, $this->clock->monotonicMs() - $this->baseMs);
    }

    public function clock(): Clock
    {
        return $this->clock;
    }
}
