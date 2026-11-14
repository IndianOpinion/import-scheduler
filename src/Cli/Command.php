<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Cli;

use IndianOpinion\ImportScheduler\DbTime;
use IndianOpinion\ImportScheduler\Exception\ImportSchedulerException;
use IndianOpinion\ImportScheduler\Exception\LockException;
use IndianOpinion\ImportScheduler\Installer;
use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\Operator;
use IndianOpinion\ImportScheduler\Plugin;
use IndianOpinion\ImportScheduler\Pruner;
use IndianOpinion\ImportScheduler\Rule;
use IndianOpinion\ImportScheduler\RuleState;
use IndianOpinion\ImportScheduler\Sync;
use IndianOpinion\ImportScheduler\Tick;

/**
 * Runs import rules on their own cron lanes, with per-rule locks and history.
 *
 * ## EXAMPLES
 *
 *     # The system cron line for the default lane (run as the site owner):
 *     * * * * * cd /path/to/site && wp import-scheduler tick --lane=default --quiet >> /path/to/log 2>&1
 *
 *     wp import-scheduler list
 *     wp import-scheduler status
 */
final class Command
{
    public const FORMATS = ['table', 'json', 'csv', 'yaml', 'ids'];

    private Plugin $plugin;
    private Io $io;

    public function __construct(?Plugin $plugin = null, ?Io $io = null)
    {
        $this->plugin = $plugin ?? Plugin::instance();
        $this->io = $io ?? new WpCliIo();
    }

    /**
     * Run the due rules of one lane within a time budget, then exit.
     *
     * ## OPTIONS
     *
     * [--lane=<lane>]
     * : Lane to tick.
     * ---
     * default: default
     * ---
     *
     * [--budget=<seconds>]
     * : Stop choosing new rules after this many seconds (5 to 3600).
     *
     * [--dry-run]
     * : Print which rules would run and why others would not. Takes no locks.
     *
     * [--strict]
     * : Exit 1 when any run in this tick failed.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function tick(array $args, array $assoc): void
    {
        $budget = null;
        if (isset($assoc['budget'])) {
            $budget = $this->intArg((string) $assoc['budget']);
            if ($budget === null || $budget < 5 || $budget > 3600) {
                $this->fail('--budget must be 5 to 3600 seconds.');
                return;
            }
        }
        $code = $this->plugin->tick()->run(
            (string) ($assoc['lane'] ?? Rule::DEFAULT_LANE),
            $budget,
            isset($assoc['dry-run']),
            isset($assoc['strict']),
            function (string $line, bool $important): void {
                $important ? $this->io->line($line) : $this->io->log($line);
            }
        );
        $this->io->halt($code);
    }

    /**
     * List rules with their schedule and last result.
     *
     * ## OPTIONS
     *
     * [--lane=<lane>]
     * : Only this lane.
     *
     * [--status=<status>]
     * : Only this status (idle, due, running, paused, failing, slow, orphan).
     *
     * [--format=<format>]
     * : table, json, csv, yaml or ids.
     * ---
     * default: table
     * ---
     *
     * @subcommand list
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function list_(array $args, array $assoc): void
    {
        $format = $this->format($assoc);
        if ($format === null) {
            return;
        }
        $operator = $this->plugin->operator();
        $now = $this->plugin->storage()->now();
        $rules = $operator->rules();
        $medians = $operator->medians(array_keys($rules));
        $rows = [];
        foreach ($rules as $id => $rule) {
            $status = $rule->status($now);
            if (isset($assoc['lane']) && $rule->lane !== $assoc['lane']) {
                continue;
            }
            if (isset($assoc['status']) && $status !== $assoc['status'] && !($assoc['status'] === 'slow' && $status === RuleState::STATUS_SLOW)) {
                continue;
            }
            $rows[] = self::ruleRow($rule, $status, $medians[$id] ?? null, $now);
        }
        $this->output($rows, ['id', 'label', 'lane', 'interval', 'status', 'last_run', 'last_duration', 'last_result', 'next_run', 'failures', 'median'], $format);
    }

    /**
     * Lanes, locks, last tick per lane, guards and the kill switch.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $settings = $this->plugin->settings(true);
        $storage = $this->plugin->storage();
        $now = $storage->now();
        $operator = $this->plugin->operator();
        $rules = $operator->rules();
        $ticks = $storage->latestTicks();
        $cadence = $settings->int('expected_cadence');

        $this->io->line('Kill switch: ' . ($settings->enabled() ? 'off (running)' : ($settings->constantDisabled() ? 'ON (IMPORT_SCHEDULER_DISABLE)' : 'ON (setting)')));
        $rows = [];
        foreach ($operator->lanes($rules) as $lane) {
            $count = count(array_filter($rules, static fn (RuleState $r): bool => $r->lane === $lane && $r->registered));
            $tick = $ticks[$lane] ?? null;
            $stale = $tick === null || $tick['started'] < $now - 3 * $cadence;
            $rows[] = [
                'lane' => $lane,
                'slots' => $settings->laneConcurrency($lane),
                'rules' => $count,
                'paused' => $settings->isLanePaused($lane) ? 'yes' : 'no',
                'last_tick' => $tick !== null ? Tick::stamp($tick['started']) : 'never',
                'outcome' => $tick !== null ? self::outcome($tick['outcome'], $tick['runs'], $tick['reason']) : '',
                'health' => $stale ? 'no tick seen in ' . (3 * $cadence) . 's: check the cron line' : 'ok',
            ];
        }
        $this->io->table($rows, ['lane', 'slots', 'rules', 'paused', 'last_tick', 'outcome', 'health'], 'table');

        $locks = [];
        foreach ($storage->allLocks() as $lock) {
            $locks[] = [
                'name' => $lock['name'],
                'holder' => substr($lock['owner'], 0, 8),
                'expires' => Tick::stamp($lock['expires']),
                'state' => $lock['expires'] < $now ? 'expired' : 'held',
            ];
        }
        if ($locks === []) {
            $this->io->line('Locks: none held.');
        } else {
            $this->io->table($locks, ['name', 'holder', 'expires', 'state'], 'table');
        }

        $guards = $this->plugin->guards($settings);
        foreach ($operator->lanes($rules) as $lane) {
            $reason = $guards->check($lane);
            $this->io->line(sprintf('Guards for %s: %s', $lane, $reason === null ? 'pass' : 'stand down (' . $reason . ')'));
            foreach ($guards->notes() as $note) {
                $this->io->line('  note: ' . $note);
            }
        }
        $missing = Installer::missingColumns();
        if ($missing !== []) {
            $this->io->warning('Missing columns: ' . Installer::describe($missing));
        }
        foreach ($operator->missingFromTick($rules) as $id) {
            $this->io->warning(sprintf('Rule %s is registered here but the last tick did not see it. Register rules unconditionally.', $id));
        }
    }

    /**
     * Run one rule now in this process. Takes the rule lock, not a lane slot.
     *
     * ## OPTIONS
     *
     * <id>
     * : Rule id.
     *
     * [--ignore-pause]
     * : Run even when the rule is paused.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $id = (string) ($args[0] ?? '');
        $rule = $this->plugin->registry()->get($id);
        if ($rule === null) {
            $this->fail('no such rule: ' . $id);
            return;
        }
        $storage = $this->plugin->storage();
        $now = $storage->now();
        $state = $storage->getRuleState($id);
        if ($state === null) {
            $storage->insertRuleStates([Sync::newRow($rule, $now, $this->plugin->nextRun())]);
            $state = $storage->getRuleState($id);
        }
        if ($state === null) {
            $this->fail('could not create the state row for ' . $id);
            return;
        }
        if ($state['paused'] === 1 && !isset($assoc['ignore-pause'])) {
            $this->fail(sprintf('rule %s is paused (%s); use --ignore-pause to run it anyway', $id, $state['pause_reason']));
            return;
        }
        $run = $this->plugin->runner()->run($rule, $state, new DbTime($now, $this->plugin->clock()));
        if ($run === null) {
            $lock = $storage->getLock(Locks::rule($id));
            $this->fail(sprintf(
                'rule %s is locked by %s until %s; refusing to run it twice',
                $id,
                $lock !== null ? substr($lock['owner'], 0, 8) : '?',
                $lock !== null ? Tick::stamp($lock['expires']) : '?'
            ));
            return;
        }
        $this->io->line(Tick::runLine($run));
        $failed = $run['status'] === 'failure' || $run['original_status'] === 'failure';
        $this->io->halt($failed ? 1 : 0);
    }

    /**
     * Make rules due on their lane's next tick.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Rule ids.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function due(array $args, array $assoc): void
    {
        $this->each($args, fn (Operator $op, string $id) => $op->markDue($id), 'due on the next tick');
    }

    /**
     * Pause rules. They keep their next run but are never chosen.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Rule ids.
     *
     * [--reason=<text>]
     * : Why, shown on the screen.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function pause(array $args, array $assoc): void
    {
        $reason = (string) ($assoc['reason'] ?? '');
        $this->each($args, fn (Operator $op, string $id) => $op->pause($id, $reason), 'paused');
    }

    /**
     * Resume paused rules. A rule whose next run is past runs once.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Rule ids.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function resume(array $args, array $assoc): void
    {
        $this->each($args, fn (Operator $op, string $id) => $op->resume($id), 'resumed');
    }

    /**
     * Override a rule's interval, or reset it to the registered value.
     *
     * ## OPTIONS
     *
     * <id>
     * : Rule id.
     *
     * [<seconds>]
     * : New interval, 60 to 2592000.
     *
     * [--reset]
     * : Go back to the registered interval.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function interval(array $args, array $assoc): void
    {
        $id = (string) ($args[0] ?? '');
        if (isset($assoc['reset'])) {
            $this->each([$id], fn (Operator $op, string $rid) => $op->resetInterval($rid), 'interval reset');
            return;
        }
        $seconds = $this->intArg((string) ($args[1] ?? ''));
        if ($seconds === null) {
            $this->fail('give the interval in seconds, or --reset');
            return;
        }
        $this->each([$id], fn (Operator $op, string $rid) => $op->setInterval($rid, $seconds), 'interval set to ' . $seconds . 's');
    }

    /**
     * Move a rule to another lane (operator override).
     *
     * ## OPTIONS
     *
     * <id>
     * : Rule id.
     *
     * <lane>
     * : Lane name.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function lane(array $args, array $assoc): void
    {
        $lane = (string) ($args[1] ?? '');
        $this->each([(string) ($args[0] ?? '')], fn (Operator $op, string $id) => $op->moveLane($id, $lane), 'moved to lane ' . $lane);
    }

    /**
     * Pause a whole lane: its ticks stand down and running rules are asked
     * to stop.
     *
     * ## OPTIONS
     *
     * <lane>
     * : Lane name.
     *
     * @subcommand pause-lane
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function pauseLane(array $args, array $assoc): void
    {
        $this->laneSwitch((string) ($args[0] ?? ''), true);
    }

    /**
     * Resume a paused lane.
     *
     * ## OPTIONS
     *
     * <lane>
     * : Lane name.
     *
     * @subcommand resume-lane
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function resumeLane(array $args, array $assoc): void
    {
        $this->laneSwitch((string) ($args[0] ?? ''), false);
    }

    /**
     * Ask a running rule to stop at its next shouldStop() check.
     *
     * ## OPTIONS
     *
     * <id>
     * : Rule id.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function stop(array $args, array $assoc): void
    {
        $id = (string) ($args[0] ?? '');
        try {
            if ($this->plugin->operator()->requestStop($id)) {
                $this->io->line($id . ': stop requested');
            } else {
                $this->io->warning($id . ' is not running; nothing to stop');
            }
        } catch (ImportSchedulerException $e) {
            $this->fail($e->getMessage());
        }
    }

    /**
     * Show the run history of one rule.
     *
     * ## OPTIONS
     *
     * <id>
     * : Rule id.
     *
     * [--limit=<n>]
     * : How many runs.
     * ---
     * default: 50
     * ---
     *
     * [--format=<format>]
     * : table, json, csv, yaml or ids.
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function history(array $args, array $assoc): void
    {
        $id = (string) ($args[0] ?? '');
        $format = $this->format($assoc);
        if ($format === null) {
            return;
        }
        try {
            $this->plugin->operator()->rule($id);
        } catch (ImportSchedulerException $e) {
            $this->fail($e->getMessage());
            return;
        }
        $limit = $this->intArg((string) ($assoc['limit'] ?? '50'));
        if ($limit === null || $limit < 1 || $limit > 1000) {
            $this->fail('--limit must be 1 to 1000');
            return;
        }
        $rows = [];
        foreach ($this->plugin->storage()->runsForRule($id, $limit) as $run) {
            $rows[] = [
                'id' => $run['id'],
                'started' => Tick::stamp($run['started']),
                'duration' => $run['duration_ms'] === null ? '' : sprintf('%.1fs', $run['duration_ms'] / 1000),
                'status' => $run['status'] . ($run['original_status'] !== '' ? ' (' . $run['original_status'] . ')' : ''),
                'items' => $run['items'] ?? '',
                'message' => $run['message'],
            ];
        }
        $this->output($rows, ['id', 'started', 'duration', 'status', 'items', 'message'], $format);
    }

    /**
     * Remove a lock. Without --force only an expired lock is removed.
     *
     * ## OPTIONS
     *
     * <name>
     * : Lock name, for example lane:default:1 or rule:my-feed.
     *
     * [--force]
     * : Remove it even if it is held and unexpired.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function unlock(array $args, array $assoc): void
    {
        $name = (string) ($args[0] ?? '');
        $force = isset($assoc['force']);
        $lock = $this->plugin->storage()->getLock($name);
        if ($lock !== null && $force) {
            $this->io->line(sprintf('Removing %s held by %s until %s.', $name, substr($lock['owner'], 0, 8), Tick::stamp($lock['expires'])));
        }
        try {
            if (!$this->plugin->locks()->unlock($name, $force)) {
                $this->fail('no such lock: ' . $name);
                return;
            }
            $this->io->line('Removed ' . $name . '.');
        } catch (LockException $e) {
            $this->fail($e->getMessage());
        }
    }

    /**
     * Remove history beyond retention and, with --orphans, orphaned rules.
     *
     * ## OPTIONS
     *
     * [--orphans]
     * : Also forget state and history of rules no longer registered.
     *
     * [--dry-run]
     * : Count only.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function prune(array $args, array $assoc): void
    {
        $dry = isset($assoc['dry-run']);
        $storage = $this->plugin->storage();
        $pruner = new Pruner($storage, $this->plugin->locks());
        $result = $pruner->run($storage->now(), $this->plugin->settings(true), $dry);
        $this->io->line(sprintf('%s %d runs and %d tick records past retention.', $dry ? 'Would remove' : 'Removed', $result['runs'], $result['ticks']));
        if (isset($assoc['orphans'])) {
            $orphans = [];
            foreach ($this->plugin->operator()->rules() as $id => $rule) {
                if (!$rule->registered) {
                    $orphans[] = $id;
                }
            }
            $forgot = $pruner->forget($orphans, $dry);
            $this->io->line(sprintf('%s %d orphaned rules and %d of their runs.', $dry ? 'Would forget' : 'Forgot', $forgot['rules'], $forgot['runs']));
        }
    }

    /**
     * Print a suggested crontab line for a lane. Never writes a file.
     *
     * ## OPTIONS
     *
     * [--lane=<lane>]
     * : Lane.
     * ---
     * default: default
     * ---
     *
     * [--user=<user>]
     * : The user that owns the site files (never root).
     *
     * [--path=<path>]
     * : The WordPress directory.
     *
     * @subcommand cron-line
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function cronLine(array $args, array $assoc): void
    {
        $lane = (string) ($assoc['lane'] ?? Rule::DEFAULT_LANE);
        if (!Rule::isValidLane($lane)) {
            $this->fail('invalid lane name: ' . $lane);
            return;
        }
        $user = isset($assoc['user']) && $assoc['user'] !== '' ? (string) $assoc['user'] : '<user>';
        $path = isset($assoc['path']) && $assoc['path'] !== '' ? (string) $assoc['path'] : '<path>';
        $this->io->line(self::cronLineFor($lane, $user, $path));
    }

    public static function cronLineFor(string $lane, string $user = '<user>', string $path = '<path>'): string
    {
        return sprintf(
            '* * * * * %s cd %s && wp import-scheduler tick --lane=%s --quiet >> <log file> 2>&1',
            $user,
            $path,
            $lane
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function ruleRow(RuleState $rule, string $status, ?int $median, int $now): array
    {
        $result = $rule->lastStatus;
        if ($rule->lastItems !== null) {
            $result .= ' items=' . $rule->lastItems;
        }
        if ($rule->lastMessage !== '') {
            $result .= ' ' . $rule->lastMessage;
        }
        return [
            'id' => $rule->id,
            'label' => $rule->label,
            'lane' => $rule->lane,
            'interval' => $rule->interval . ($rule->intervalOverride !== null ? ' (overridden)' : ''),
            'status' => $status,
            'last_run' => $rule->lastStart !== null ? Tick::stamp($rule->lastStart) : '',
            'last_duration' => $rule->lastDurationMs !== null ? sprintf('%.1fs', $rule->lastDurationMs / 1000) : '',
            'last_result' => trim($result),
            'next_run' => $rule->nextRun === null || $rule->nextRun <= $now ? 'now' : Tick::stamp($rule->nextRun),
            'failures' => $rule->consecutiveFailures,
            'median' => $median !== null ? sprintf('%.1fs', $median / 1000) : '',
        ];
    }

    public static function outcome(string $outcome, int $runs, string $reason): string
    {
        switch ($outcome) {
            case 'ran':
                $text = 'ran ' . $runs;
                break;
            case 'nothing_due':
                $text = 'nothing due';
                break;
            case 'stood_down':
                $text = 'stood down';
                break;
            default:
                $text = str_replace('_', ' ', $outcome);
        }
        return $reason !== '' ? $text . ' (' . $reason . ')' : $text;
    }

    private function laneSwitch(string $lane, bool $paused): void
    {
        $errors = $this->plugin->operator()->setLanePaused($lane, $paused);
        if ($errors !== []) {
            $this->fail(implode(' ', $errors));
            return;
        }
        $this->io->line(sprintf('Lane %s %s.', $lane, $paused ? 'paused' : 'resumed'));
    }

    /**
     * @param list<string>                      $ids
     * @param callable(Operator, string): mixed $action
     */
    private function each(array $ids, callable $action, string $done): void
    {
        $operator = $this->plugin->operator();
        $failed = false;
        if ($ids === [] || $ids === ['']) {
            $this->fail('give at least one rule id');
            return;
        }
        foreach ($ids as $id) {
            try {
                $action($operator, $id);
                $this->io->line($id . ': ' . $done);
            } catch (ImportSchedulerException $e) {
                $this->io->error($e->getMessage());
                $failed = true;
            }
        }
        $this->io->halt($failed ? 1 : 0);
    }

    /**
     * @param array<string, string> $assoc
     */
    private function format(array $assoc): ?string
    {
        $format = (string) ($assoc['format'] ?? 'table');
        if (!in_array($format, self::FORMATS, true)) {
            $this->fail('--format must be one of ' . implode(', ', self::FORMATS));
            return null;
        }
        return $format;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $fields
     */
    private function output(array $rows, array $fields, string $format): void
    {
        if ($format === 'ids') {
            $this->io->line(implode(' ', array_map(static fn (array $r): string => (string) $r[$fields[0]], $rows)));
            return;
        }
        $this->io->table($rows, $fields, $format);
    }

    private function intArg(string $value): ?int
    {
        return preg_match('/^\d+$/', trim($value)) === 1 ? (int) $value : null;
    }

    private function fail(string $message): void
    {
        $this->io->error($message);
        $this->io->halt(1);
    }
}
