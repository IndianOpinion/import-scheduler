<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Next run arithmetic. Missed slots are never replayed as a burst; a long
 * run rests a full interval; failures back off exponentially to a cap.
 */
final class NextRun
{
    /** @var callable(int, int): int */
    private $random;

    /**
     * @param (callable(int, int): int)|null $random Returns an integer in [min, max].
     */
    public function __construct(?callable $random = null)
    {
        $this->random = $random ?? static fn (int $min, int $max): int => random_int($min, $max);
    }

    /**
     * Success within limits: scheduled time plus the interval, or now plus
     * the interval when that is already past (at most one catch-up run).
     */
    public function afterSuccess(int $scheduled, int $now, int $interval, int $jitter = 0): int
    {
        $next = $scheduled + $interval;
        if ($next <= $now) {
            $next = $now + $interval;
        }
        return $next + $this->jitter($jitter);
    }

    /** Overrun: rest one full interval from the finish. */
    public function afterOverrun(int $finished, int $interval, int $jitter = 0): int
    {
        return $finished + $interval + $this->jitter($jitter);
    }

    public function afterFailure(int $finished, int $interval, int $failures, int $cap, int $jitter = 0): int
    {
        return $finished + self::backoff($interval, $failures, $cap) + $this->jitter($jitter);
    }

    /**
     * Interval times 2^(failures - 1), capped at the larger of the interval
     * and the cap. The first failure retries at the normal interval.
     */
    public static function backoff(int $interval, int $failures, int $cap): int
    {
        $ceiling = max($interval, $cap);
        $exponent = max(0, min(30, $failures - 1));
        return (int) min($ceiling, $interval * (2 ** $exponent));
    }

    public function jitter(int $jitter): int
    {
        return $jitter > 0 ? ($this->random)(0, $jitter) : 0;
    }
}
