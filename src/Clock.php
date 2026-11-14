<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * A monotonic clock for durations and budgets. Never wall time.
 */
interface Clock
{
    /** Milliseconds from an arbitrary fixed point; never goes backwards. */
    public function monotonicMs(): float;
}
