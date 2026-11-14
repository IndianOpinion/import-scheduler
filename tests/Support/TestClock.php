<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Support;

use IndianOpinion\ImportScheduler\Clock;

final class TestClock implements Clock
{
    private float $ms = 0.0;

    public function monotonicMs(): float
    {
        return $this->ms;
    }

    public function advance(float $seconds): void
    {
        $this->ms += $seconds * 1000;
    }
}
