<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

use IndianOpinion\ImportScheduler\Exception\SchemaException;
use IndianOpinion\ImportScheduler\Exception\StorageException;
use IndianOpinion\ImportScheduler\Storage\Storage;

/**
 * One tick of one lane: guards, a lane slot, sync, due rules oldest first
 * within a wall-clock budget, release, record.
 *
 * Output goes to $out(string $line, bool $important). Important lines
 * (failures, the summary, stand-downs) are printed even under --quiet.
 *
 * @phpstan-import-type StateRow from Storage
 */
final class Tick
{
    public const DUE_LIMIT = 200;
    public const SOURCE_CLI = 'cli';
    public const SOURCE_WP_CRON = 'wp-cron';

    /** @var callable(bool): Settings */
    private $settings;

    /** @var (callable(): bool)|null */
    private $installer;

    /** @var callable(Settings): Guards */
    private $guards;

    /**
     * @param callable(bool): Settings        $settings
     * @param callable(Settings): Guards      $guards
     * @param (callable(): bool)|null         $installer Creates the tables; true on success.
     */
    public function __construct(
        private Storage $storage,
        private Registry $registry,
        callable $settings,
        private Locks $locks,
        private Runner $runner,
        private NextRun $nextRun,
        callable $guards,
        private Memory $memory,
        private Clock $clock,
        ?callable $installer = null
    ) {
        $this->settings = $settings;
        $this->guards = $guards;
        $this->installer = $installer;
    }

    /**
     * @param callable(string, bool): void $out
     * @return int Exit status.
     */
    public function run(
        string $lane,
        ?int $budget,
        bool $dryRun,
        bool $strict,
        callable $out,
        string $source = self::SOURCE_CLI
    ): int {
        if (!Rule::isValidLane($lane)) {
            $out(sprintf('Invalid lane name "%s".', $lane), true);
            return 1;
        }
        $settings = ($this->settings)(true);
        $budget = $budget ?? $settings->int('tick_budget');
        try {
            $now = $this->storage->now();
        } catch (StorageException $e) {
            $out('Database unreachable: ' . $e->getMessage(), true);
            return 1;
        }
        $time = new DbTime($now, $this->clock);

        $attempts = 0;
        while (true) {
            try {
                return $this->body($lane, $budget, $dryRun, $strict, $out, $source, $settings, $time);
            } catch (SchemaException $e) {
                if ($attempts > 0 || $this->installer === null) {
                    $out('Import scheduler tables are missing and could not be created: ' . $e->getMessage(), true);
                    return 1;
                }
                $attempts++;
                if (!($this->installer)()) {
                    $out('Import scheduler tables are missing and could not be created. Activate the plugin or check database permissions.', true);
                    return 1;
                }
            } catch (StorageException $e) {
                $out('Database error: ' . $e->getMessage(), true);
                return 1;
            }
        }
    }

    /**
     * @param callable(string, bool): void $out
     */
    private function body(
        string $lane,
        int $budget,
        bool $dryRun,
        bool $strict,
        callable $out,
        string $source,
        Settings $settings,
        DbTime $time
    ): int {
        $now = $time->start();
        if ($source === self::SOURCE_WP_CRON) {
            $lastCli = $this->storage->lastTickStart($lane, self::SOURCE_CLI);
            if ($lastCli !== null && $lastCli >= $now - 3600) {
                return 0;
            }
        }
        if (!$settings->enabled()) {
            $out('disabled', true);
            if (!$dryRun) {
                $this->record($lane, 0, $source, $now, $time, 'disabled', $settings->constantDisabled() ? 'IMPORT_SCHEDULER_DISABLE is set' : 'kill switch is on', 0, 0);
            }
            return 0;
        }
        if ($this->registry->all() === []) {
            $out('no rules', true);
            return 0;
        }
        $reason = (($this->guards)($settings))->check($lane);
        if ($reason !== null) {
            $out('stood down: ' . $reason, true);
            if (!$dryRun) {
                $this->record($lane, 0, $source, $now, $time, 'stood_down', $reason, 0, 0);
            }
            return 0;
        }
        if ($dryRun) {
            return $this->dryRun($lane, $now, $budget, $settings, $out);
        }

        $grace = $settings->int('lock_grace');
        $ttl = Locks::laneTtl($budget, $this->largestMaxRuntime($lane, $settings), $grace);
        $owner = Locks::newOwner();
        $slot = 0;
        $slotName = '';
        for ($s = 1; $s <= $settings->laneConcurrency($lane); $s++) {
            if ($this->locks->take(Locks::laneSlot($lane, $s), $owner, $ttl)) {
                $slot = $s;
                $slotName = Locks::laneSlot($lane, $s);
                break;
            }
        }
        if ($slot === 0) {
            $out('lane busy', true);
            $this->record($lane, 0, $source, $now, $time, 'busy', '', 0, 0);
            return 0;
        }

        $ran = 0;
        $failed = 0;
        $skipped = 0;
        $stopReason = '';
        $this->runner->setSlot($slotName, $owner);
        try {
            $sync = new Sync($this->storage, $this->registry, $settings, $this->nextRun);
            $sync->run($now, true, $lane);
            $due = $this->storage->dueRuleStates($lane, $now, self::DUE_LIMIT);
            foreach ($due as $state) {
                if ($time->elapsedMs() >= $budget * 1000) {
                    $stopReason = 'budget spent';
                    break;
                }
                $rule = $this->registry->get($state['rule_id']);
                if ($rule === null) {
                    continue;
                }
                $run = $this->runner->run($rule, $state, $time);
                if ($run === null) {
                    $skipped++;
                    $out(sprintf('%s %s %s skipped: rule lock held', self::stamp($time->now()), $lane, $rule->id), false);
                    continue;
                }
                $ran++;
                $isFailure = $run['status'] === 'failure' || $run['original_status'] === 'failure';
                if ($isFailure) {
                    $failed++;
                }
                $out(self::runLine($run), $isFailure);
                $this->locks->renew($slotName, $owner, $ttl);
                $fresh = ($this->settings)(true);
                if (!$fresh->enabled()) {
                    $stopReason = 'disabled';
                    break;
                }
                if ($fresh->isLanePaused($lane)) {
                    $stopReason = 'lane paused';
                    break;
                }
                if ($this->memory->aboveCeiling($fresh->int('memory_ceiling_pct'))) {
                    $stopReason = 'memory ceiling reached';
                    break;
                }
            }
        } finally {
            $this->runner->setSlot(null, null);
            try {
                $this->locks->release($slotName, $owner);
            } catch (StorageException $e) {
                Log::error('could not release lane slot ' . $slotName . ': ' . $e->getMessage());
            }
        }

        $outcome = $ran > 0 ? 'ran' : 'nothing_due';
        $this->record($lane, $slot, $source, $now, $time, $outcome, $stopReason, $ran, $failed);
        (new Pruner($this->storage, $this->locks))->maybeRun($now, $settings, $settings->int('expected_cadence') + $budget);
        $summary = [
            'lane' => $lane,
            'slot' => $slot,
            'started' => $now,
            'ended' => $time->now(),
            'runs' => $ran,
            'failures' => $failed,
            'skipped' => $skipped,
            'outcome' => $outcome,
            'reason' => $stopReason,
        ];
        do_action('import_scheduler_tick_finished', $summary);
        $out(sprintf(
            '%s %s tick: %s, ran %d, failed %d, skipped %d, %.1fs%s',
            self::stamp($time->now()),
            $lane,
            $outcome === 'ran' ? 'ran' : 'nothing due',
            $ran,
            $failed,
            $skipped,
            $time->elapsedMs() / 1000,
            $stopReason !== '' ? ', stopped: ' . $stopReason : ''
        ), true);
        return ($strict && $failed > 0) ? 1 : 0;
    }

    /**
     * @param callable(string, bool): void $out
     */
    private function dryRun(string $lane, int $now, int $budget, Settings $settings, callable $out): int
    {
        $states = $this->storage->allRuleStates();
        $due = [];
        $notes = [];
        foreach ($this->registry->all() as $id => $rule) {
            $state = $states[$id] ?? Sync::newRow($rule, $now, new NextRun(static fn (int $min, int $max): int => $min));
            $effectiveLane = $state['lane_override'] ?? $rule->lane;
            if ($effectiveLane !== $lane) {
                continue;
            }
            if ($state['paused'] === 1) {
                $notes[] = sprintf('%s: paused%s', $id, $state['pause_reason'] !== '' ? ' (' . $state['pause_reason'] . ')' : '');
                continue;
            }
            if ($state['next_run'] > $now) {
                $notes[] = sprintf('%s: not due until %s', $id, self::stamp($state['next_run']));
                continue;
            }
            $lock = $this->storage->getLock(Locks::rule($id));
            if ($lock !== null && $lock['expires'] >= $now) {
                $notes[] = sprintf('%s: rule lock held until %s', $id, self::stamp($lock['expires']));
                continue;
            }
            $due[] = $state;
        }
        foreach ($states as $id => $state) {
            if (!$this->registry->has($id) && $state['lane'] === $lane) {
                $notes[] = sprintf('%s: orphan (not registered in this process)', $id);
            }
        }
        usort($due, static fn (array $a, array $b): int => [$a['next_run'], $a['rule_id']] <=> [$b['next_run'], $b['rule_id']]);
        $out(sprintf('dry run: lane %s, budget %ds, %d due', $lane, $budget, count($due)), true);
        $n = 0;
        foreach (array_slice($due, 0, self::DUE_LIMIT) as $state) {
            $n++;
            $out(sprintf('would run %d: %s (due since %s)', $n, $state['rule_id'], self::stamp($state['next_run'])), true);
        }
        foreach ($notes as $note) {
            $out('would skip ' . $note, true);
        }
        return 0;
    }

    private function largestMaxRuntime(string $lane, Settings $settings): int
    {
        $largest = 0;
        foreach ($this->registry->all() as $rule) {
            if ($rule->lane === $lane) {
                $largest = max($largest, $rule->effectiveMaxRuntime($rule->interval, $settings->int('default_max_runtime')));
            }
        }
        return $largest > 0 ? $largest : $settings->int('default_max_runtime');
    }

    private function record(
        string $lane,
        int $slot,
        string $source,
        int $started,
        DbTime $time,
        string $outcome,
        string $reason,
        int $runs,
        int $failures
    ): void {
        $this->storage->insertTick([
            'lane' => $lane,
            'slot' => $slot,
            'source' => $source,
            'started' => $started,
            'finished' => $time->now(),
            'outcome' => $outcome,
            'reason' => Text::head($reason, 191),
            'runs' => $runs,
            'failures' => $failures,
        ]);
    }

    /**
     * @param array{rule_id: string, lane: string, finished: ?int, status: string, original_status: string, duration_ms: ?int, items: ?int, message: string} $run
     */
    public static function runLine(array $run): string
    {
        $status = $run['status'] . ($run['original_status'] !== '' ? '(' . $run['original_status'] . ')' : '');
        $message = Text::oneLine($run['message']);
        if (mb_strlen($message) > 120) {
            $message = mb_substr($message, 0, 120);
        }
        return trim(sprintf(
            '%s %s %s %s %.1fs items=%s %s',
            self::stamp((int) $run['finished']),
            $run['lane'],
            $run['rule_id'],
            $status,
            ((int) $run['duration_ms']) / 1000,
            $run['items'] === null ? '-' : (string) $run['items'],
            $message
        ));
    }

    public static function stamp(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }
}
