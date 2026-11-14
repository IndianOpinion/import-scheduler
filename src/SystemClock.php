<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

final class SystemClock implements Clock
{
    public function monotonicMs(): float
    {
        return hrtime(true) / 1e6;
    }
}
