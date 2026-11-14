<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

use IndianOpinion\ImportScheduler\Storage\Storage;

/**
 * Retention: the newest history_keep runs per rule, nothing older than
 * history_days, tick records for seven days. At most 5,000 rows per pass and
 * at most one pass per day, claimed through a lock row that expires after a
 * day.
 */
final class Pruner
{
    public const MAX_ROWS = 5000;
    public const TICK_DAYS = 7;
    public const LOCK = 'prune:daily';

    public function __construct(private Storage $storage, private Locks $locks)
    {
    }

    /**
     * Prune if no pass has run in the last day. Only ticks that start in the
     * first minutes of an hour try the claim, so most ticks spend no query
     * on it.
     *
     * @return array{runs: int, ticks: int}|null Null when no pass ran.
     */
    public function maybeRun(int $now, Settings $settings, int $window): ?array
    {
        if ($now % 3600 >= $window) {
            return null;
        }
        if (!$this->locks->take(self::LOCK, Locks::newOwner(), 86400 - $window)) {
            return null;
        }
        return $this->run($now, $settings, false);
    }

    /**
     * @return array{runs: int, ticks: int}
     */
    public function run(int $now, Settings $settings, bool $dryRun): array
    {
        $runs = $this->storage->pruneRuns(
            $settings->int('history_keep'),
            $now - $settings->int('history_days') * 86400,
            self::MAX_ROWS,
            $dryRun
        );
        $ticks = $this->storage->pruneTicks($now - self::TICK_DAYS * 86400, max(0, self::MAX_ROWS - ($dryRun ? 0 : $runs)), $dryRun);
        return ['runs' => $runs, 'ticks' => $ticks];
    }

    /**
     * Forget orphans: state rows and history for ids that are not registered.
     *
     * @param list<string> $ids
     * @return array{rules: int, runs: int}
     */
    public function forget(array $ids, bool $dryRun): array
    {
        $rules = 0;
        $runs = 0;
        foreach ($ids as $id) {
            if ($dryRun) {
                $rules++;
                $runs += count($this->storage->runsForRule($id, 1000000));
                continue;
            }
            $runs += $this->storage->deleteRunsForRule($id);
            $rules += $this->storage->deleteRuleState($id) ? 1 : 0;
        }
        return ['rules' => $rules, 'runs' => $runs];
    }
}
