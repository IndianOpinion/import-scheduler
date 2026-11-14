<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

use IndianOpinion\ImportScheduler\Exception\InvalidRuleException;
use IndianOpinion\ImportScheduler\Storage\Storage;

/**
 * Operator actions shared by the screen and WP-CLI. None of them runs a
 * rule: "run now" only marks a rule due.
 *
 * @phpstan-import-type StateRow from Storage
 */
final class Operator
{
    /** @var callable(bool): Settings */
    private $settings;

    /** @var callable(array<string, mixed>): void */
    private $saveSettings;

    /**
     * @param callable(bool): Settings              $settings
     * @param callable(array<string, mixed>): void  $saveSettings
     */
    public function __construct(
        private Storage $storage,
        private Registry $registry,
        callable $settings,
        callable $saveSettings,
        private NextRun $nextRun
    ) {
        $this->settings = $settings;
        $this->saveSettings = $saveSettings;
    }

    /**
     * Every rule known to this request or stored, as snapshots.
     *
     * @param bool $sync Create missing rows and sweep abandoned runs first (the screen does).
     * @return array<string, RuleState>
     */
    public function rules(bool $sync = false): array
    {
        $settings = ($this->settings)(false);
        if ($sync) {
            $states = (new Sync($this->storage, $this->registry, $settings, $this->nextRun))->run($this->storage->now(), false);
        } else {
            $states = $this->storage->allRuleStates();
        }
        $out = [];
        foreach ($this->registry->all() as $id => $rule) {
            $snapshot = RuleState::from($rule, $states[$id] ?? null, $settings);
            if ($snapshot !== null) {
                $out[$id] = $snapshot;
            }
        }
        foreach ($states as $id => $row) {
            if (!isset($out[$id])) {
                $snapshot = RuleState::from(null, $row, $settings);
                if ($snapshot !== null) {
                    $out[$id] = $snapshot;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * @throws InvalidRuleException When the id is unknown.
     */
    public function rule(string $id): RuleState
    {
        $snapshot = RuleState::from($this->registry->get($id), $this->storage->getRuleState($id), ($this->settings)(false));
        if ($snapshot === null) {
            throw new InvalidRuleException('no such rule: ' . $id);
        }
        return $snapshot;
    }

    /**
     * Medians over the last $n finished runs per rule, computed in PHP.
     *
     * @param list<string> $ids
     * @return array<string, int> Milliseconds.
     */
    public function medians(array $ids, int $n = 20): array
    {
        $out = [];
        foreach ($this->storage->recentDurations($ids, $n) as $id => $durations) {
            $median = self::median($durations);
            if ($median !== null) {
                $out[$id] = $median;
            }
        }
        return $out;
    }

    /**
     * @param list<int> $values
     */
    public static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $values[$mid];
        }
        return (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    public function markDue(string $id): void
    {
        $this->ensureRow($id);
        $this->storage->updateRuleState($id, ['next_run' => $this->storage->now(), 'updated' => $this->storage->now()]);
    }

    public function pause(string $id, string $reason = ''): void
    {
        $this->ensureRow($id);
        $reason = Text::head(Text::oneLine($reason !== '' ? $reason : 'paused by operator'), 191);
        $this->storage->updateRuleState($id, ['paused' => 1, 'pause_reason' => $reason, 'updated' => $this->storage->now()]);
        do_action('import_scheduler_rule_paused', $id, $reason);
    }

    public function resume(string $id): void
    {
        $this->ensureRow($id);
        $this->storage->updateRuleState($id, ['paused' => 0, 'pause_reason' => '', 'consecutive_failures' => 0, 'updated' => $this->storage->now()]);
    }

    /**
     * Override the interval. The current next run is pulled in to now plus
     * the new interval when that is sooner.
     */
    public function setInterval(string $id, int $seconds): void
    {
        if ($seconds < Rule::MIN_INTERVAL || $seconds > Rule::MAX_INTERVAL) {
            throw new InvalidRuleException(sprintf('interval %d is outside 60 to 2592000 seconds', $seconds));
        }
        $state = $this->ensureRow($id);
        $now = $this->storage->now();
        $this->storage->updateRuleState($id, [
            'interval_override' => $seconds,
            'next_run' => min($state['next_run'], $now + $seconds),
            'updated' => $now,
        ]);
    }

    public function resetInterval(string $id): void
    {
        $rule = $this->registry->get($id);
        if ($rule === null) {
            throw new InvalidRuleException('no such registered rule: ' . $id);
        }
        $state = $this->ensureRow($id);
        $now = $this->storage->now();
        $this->storage->updateRuleState($id, [
            'interval_override' => null,
            'next_run' => min($state['next_run'], $now + $rule->interval),
            'updated' => $now,
        ]);
    }

    /**
     * Move a rule to another lane. A run in progress finishes under its old
     * lane; the next run happens on the new one.
     */
    public function moveLane(string $id, string $lane): void
    {
        if (!Rule::isValidLane($lane)) {
            throw new InvalidRuleException('invalid lane name: ' . $lane);
        }
        $this->ensureRow($id);
        $rule = $this->registry->get($id);
        $override = ($rule !== null && $rule->lane === $lane) ? null : $lane;
        $this->storage->updateRuleState($id, ['lane_override' => $override, 'lane' => $lane, 'updated' => $this->storage->now()]);
    }

    /**
     * Ask a running rule to stop at its next shouldStop() check.
     *
     * @return bool False when the rule is not running.
     */
    public function requestStop(string $id): bool
    {
        $state = $this->ensureRow($id);
        if ($state['running_run_id'] === null) {
            return false;
        }
        $this->storage->updateRuleState($id, ['stop_requested' => 1]);
        return true;
    }

    /**
     * Forget an orphan: its state row and history.
     */
    public function forget(string $id): void
    {
        if ($this->registry->has($id)) {
            throw new InvalidRuleException('rule ' . $id . ' is registered; only orphans can be forgotten');
        }
        if ($this->storage->getRuleState($id) === null) {
            throw new InvalidRuleException('no such rule: ' . $id);
        }
        (new Pruner($this->storage, new Locks($this->storage)))->forget([$id], false);
    }

    /**
     * @return list<string> Errors; empty on success.
     */
    public function setLanePaused(string $lane, bool $paused): array
    {
        if (!Rule::isValidLane($lane)) {
            return ['invalid lane name: ' . $lane];
        }
        $settings = ($this->settings)(true);
        $current = $settings->toArray()['paused_lanes'];
        $lanes = is_array($current) ? $current : [];
        $lanes = $paused ? array_merge($lanes, [$lane]) : array_diff($lanes, [$lane]);
        return $this->saveSettings(['paused_lanes' => array_values(array_unique($lanes))]);
    }

    /**
     * Validate and store settings. Invalid values are rejected and the
     * previous value kept.
     *
     * @param array<string, mixed> $changes
     * @return list<string> Errors.
     */
    public function saveSettings(array $changes): array
    {
        [$next, $errors] = ($this->settings)(true)->with($changes);
        ($this->saveSettings)($next->toArray());
        return $errors;
    }

    /**
     * Lanes known from registration, overrides, settings and tick records.
     *
     * @param array<string, RuleState> $rules
     * @return list<string>
     */
    public function lanes(array $rules): array
    {
        $lanes = [Rule::DEFAULT_LANE => true];
        foreach ($rules as $rule) {
            $lanes[$rule->lane] = true;
        }
        $settings = ($this->settings)(false)->toArray();
        foreach (array_keys(is_array($settings['lane_concurrency']) ? $settings['lane_concurrency'] : []) as $lane) {
            $lanes[(string) $lane] = true;
        }
        foreach (array_keys($this->storage->latestTicks()) as $lane) {
            $lanes[$lane] = true;
        }
        $names = array_map('strval', array_keys($lanes));
        sort($names);
        return $names;
    }

    /**
     * Rules registered here (wp-admin) that the last tick of their lane saw
     * as orphans: registered behind a condition that is false under WP-CLI.
     *
     * @param array<string, RuleState> $rules
     * @return list<string>
     */
    public function missingFromTick(array $rules): array
    {
        $out = [];
        foreach ($rules as $id => $rule) {
            if ($rule->registered && $rule->tickOrphan) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * @return StateRow
     */
    private function ensureRow(string $id): array
    {
        $state = $this->storage->getRuleState($id);
        if ($state !== null) {
            return $state;
        }
        $rule = $this->registry->get($id);
        if ($rule === null) {
            throw new InvalidRuleException('no such rule: ' . $id);
        }
        $row = Sync::newRow($rule, $this->storage->now(), $this->nextRun);
        $this->storage->insertRuleStates([$row]);
        return $this->storage->getRuleState($id) ?? $row;
    }
}
