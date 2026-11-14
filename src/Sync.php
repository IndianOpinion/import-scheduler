<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

use IndianOpinion\ImportScheduler\Storage\Storage;

/**
 * Brings stored state in line with the rules registered in this request,
 * with one read of all state rows:
 *
 * - creates rows for newly seen rules (due at once, or after start_delay);
 * - keeps the stored effective lane in step with registration and overrides;
 * - (tick only) flags rows no longer registered as orphans for the due query;
 * - pulls in next run values parked far in the future by a clock jump;
 * - marks runs abandoned when their process died and their lock is gone.
 *
 * @phpstan-import-type StateRow from Storage
 */
final class Sync
{
    public function __construct(
        private Storage $storage,
        private Registry $registry,
        private Settings $settings,
        private NextRun $nextRun
    ) {
    }

    /**
     * @param bool        $fromTick Only a tick writes the orphan flag: it is
     *                              what the WP-CLI process sees that matters.
     * @param string|null $lane     Sweep abandoned runs in this lane only; null for all.
     * @return array<string, StateRow> The state rows after sync, keyed by rule id.
     */
    public function run(int $now, bool $fromTick, ?string $lane = null): array
    {
        $states = $this->storage->allRuleStates();
        $new = [];
        foreach ($this->registry->all() as $id => $rule) {
            $state = $states[$id] ?? null;
            if ($state === null) {
                $row = self::newRow($rule, $now, $this->nextRun);
                $new[] = $row;
                $states[$id] = $row;
                continue;
            }
            $changes = [];
            $effectiveLane = $state['lane_override'] ?? $rule->lane;
            if ($state['lane'] !== $effectiveLane) {
                $changes['lane'] = $effectiveLane;
            }
            if ($fromTick && $state['tick_orphan'] !== 0) {
                $changes['tick_orphan'] = 0;
            }
            $pulled = $this->pullIn($rule, $state, $now);
            if ($pulled !== null) {
                $changes['next_run'] = $pulled;
            }
            if ($changes !== []) {
                $changes['updated'] = $now;
                $this->storage->updateRuleState($id, $changes);
                $states[$id] = array_merge($state, $changes);
            }
        }
        if ($new !== []) {
            $this->storage->insertRuleStates($new);
        }
        if ($fromTick) {
            foreach ($states as $id => $state) {
                if (!$this->registry->has($id) && $state['tick_orphan'] === 0) {
                    $this->storage->updateRuleState($id, ['tick_orphan' => 1]);
                    $states[$id]['tick_orphan'] = 1;
                }
            }
        }
        foreach ($states as $id => $state) {
            if ($state['running_run_id'] === null || ($lane !== null && $state['lane'] !== $lane)) {
                continue;
            }
            $swept = $this->sweep($state, $now);
            if ($swept !== null) {
                $states[$id] = $swept;
            }
        }
        return $states;
    }

    /**
     * @return StateRow
     */
    public static function newRow(Rule $rule, int $now, NextRun $nextRun): array
    {
        return [
            'rule_id' => $rule->id,
            'lane' => $rule->lane,
            'lane_override' => null,
            'interval_override' => null,
            'paused' => 0,
            'pause_reason' => '',
            'next_run' => $rule->startDelay > 0 ? $now + $rule->startDelay + $nextRun->jitter($rule->jitter) : $now,
            'last_start' => null,
            'last_finish' => null,
            'last_duration_ms' => null,
            'last_status' => '',
            'last_items' => null,
            'last_message' => '',
            'consecutive_failures' => 0,
            'consecutive_overruns' => 0,
            'last_success_start' => null,
            'stop_requested' => 0,
            'running_run_id' => null,
            'tick_orphan' => 0,
            'first_seen' => $now,
            'updated' => $now,
        ];
    }

    /**
     * A next run further ahead than any legitimate schedule could put it is
     * a clock error: pull it in to now plus the interval.
     *
     * @param StateRow $state
     */
    private function pullIn(Rule $rule, array $state, int $now): ?int
    {
        $interval = $state['interval_override'] ?? $rule->interval;
        $horizon = 2 * $interval;
        if ($state['consecutive_failures'] > 0) {
            $horizon = max($horizon, 2 * NextRun::backoff($interval, $state['consecutive_failures'], $this->settings->int('backoff_cap')));
        }
        if ($state['last_start'] === null) {
            $horizon = max($horizon, $rule->startDelay + $rule->jitter + $interval);
        }
        if ($state['next_run'] > $now + $horizon) {
            return $now + $interval;
        }
        return null;
    }

    /**
     * A running row whose rule lock is gone or expired belongs to a dead
     * process: mark it abandoned and treat it as a failure.
     *
     * @param StateRow $state
     * @return StateRow|null
     */
    private function sweep(array $state, int $now): ?array
    {
        $runId = (int) $state['running_run_id'];
        $lock = $this->storage->getLock(Locks::rule($state['rule_id']));
        $run = $this->storage->getRun($runId);
        if ($run !== null && $lock !== null && $lock['expires'] >= $now && hash_equals($lock['owner'], $run['owner'])) {
            return null;
        }
        if ($run !== null && $lock !== null && $lock['expires'] >= $now && !hash_equals($lock['owner'], $run['owner'])) {
            // Another live run holds the lock; this row is stale but its owner may still finish.
            return null;
        }
        $finished = $lock !== null && $lock['expires'] < $now ? $lock['expires'] : $now;
        if ($run !== null) {
            $finished = max($finished, $run['started']);
            $changed = $this->storage->updateRun($runId, [
                'status' => 'abandoned',
                'finished' => $finished,
                'message' => 'process died before the run finished',
            ], ['status' => 'running']);
            if (!$changed) {
                return null;
            }
        }
        $rule = $this->registry->get($state['rule_id']);
        $interval = $state['interval_override'] ?? ($rule !== null ? $rule->interval : Rule::MIN_INTERVAL);
        $failures = $state['consecutive_failures'] + 1;
        $fields = [
            'running_run_id' => null,
            'last_finish' => $finished,
            'last_status' => 'abandoned',
            'last_message' => 'process died before the run finished',
            'consecutive_failures' => $failures,
            'next_run' => $this->nextRun->afterFailure($now, $interval, $failures, $this->settings->int('backoff_cap'), $rule !== null ? $rule->jitter : 0),
            'stop_requested' => 0,
            'updated' => $now,
        ];
        $this->storage->updateRuleState($state['rule_id'], $fields, ['running_run_id' => $runId]);
        Log::error(sprintf('rule %s: run %d abandoned (process died); counted as a failure', $state['rule_id'], $runId));
        return array_merge($state, $fields);
    }
}
