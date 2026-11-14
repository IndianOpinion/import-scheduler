<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

use IndianOpinion\ImportScheduler\Exception\StorageException;
use IndianOpinion\ImportScheduler\Storage\Storage;

/**
 * Executes one run of one rule: lock, run row, call, result mapping, next
 * run, release. Used by the tick and by the manual run command.
 *
 * A shutdown handler closes the run as a failure when the runner calls exit
 * or dies with a fatal error, and frees the locks this process still owns.
 *
 * @phpstan-import-type StateRow from Storage
 * @phpstan-import-type RunRow from Storage
 * @phpstan-type Active array{run_id: int, rule: Rule, state: StateRow, lane: string, owner: string, started: int, interval: int, max_runtime: int, ob_level: int, context: RunContext, time: DbTime, start_ms: float}
 */
final class Runner
{
    public const MESSAGE_LIMIT = 500;
    public const STOP_POLL_SECONDS = 5.0;

    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /** @var Active|null */
    private ?array $active = null;

    /** @var array{name: string, owner: string}|null */
    private ?array $slot = null;

    private bool $shutdownRegistered = false;

    /** @var callable(bool): Settings */
    private $settings;

    /** @var callable(): ?array{type: int, message: string, file: string, line: int} */
    private $lastError;

    /** Seconds between database polls for a stop request. Tests set 0. */
    public float $stopPollSeconds = self::STOP_POLL_SECONDS;

    /**
     * @param callable(bool): Settings $settings Loads settings; true means bypass caches.
     * @param (callable(): ?array{type: int, message: string, file: string, line: int})|null $lastError
     */
    public function __construct(
        private Storage $storage,
        private Locks $locks,
        callable $settings,
        private Clock $clock,
        private NextRun $nextRun,
        ?callable $lastError = null,
        private bool $registerShutdown = true
    ) {
        $this->settings = $settings;
        $this->lastError = $lastError ?? static fn (): ?array => error_get_last();
    }

    /**
     * The lane slot the current tick holds, so the shutdown handler can
     * free it.
     */
    public function setSlot(?string $name, ?string $owner): void
    {
        $this->slot = ($name !== null && $owner !== null) ? ['name' => $name, 'owner' => $owner] : null;
    }

    /**
     * Run a rule if its lock can be taken.
     *
     * @param StateRow $state
     * @return RunRow|null Null when the rule lock is held by someone else.
     */
    public function run(Rule $rule, array $state, DbTime $time): ?array
    {
        $settings = ($this->settings)(false);
        $interval = $state['interval_override'] ?? $rule->interval;
        $maxRuntime = $rule->effectiveMaxRuntime($interval, $settings->int('default_max_runtime'));
        $grace = $settings->int('lock_grace');
        $lockName = Locks::rule($rule->id);
        $owner = Locks::newOwner();
        if (!$this->locks->take($lockName, $owner, Locks::ruleTtl($maxRuntime, $grace))) {
            return null;
        }
        $lane = $state['lane'];
        $started = $time->now();
        try {
            $runId = $this->storage->insertRun([
                'rule_id' => $rule->id,
                'lane' => $lane,
                'started' => $started,
                'status' => 'running',
                'owner' => $owner,
            ]);
            $this->storage->updateRuleState($rule->id, [
                'last_start' => $started,
                'running_run_id' => $runId,
                'stop_requested' => 0,
                'updated' => $started,
            ]);
        } catch (StorageException $e) {
            $this->locks->release($lockName, $owner);
            throw $e;
        }

        $startMs = $this->clock->monotonicMs();
        $lastPoll = null;
        $stopSeen = false;
        $shouldStop = function () use ($rule, $lane, $maxRuntime, $startMs, &$lastPoll, &$stopSeen): bool {
            if ($stopSeen) {
                return true;
            }
            $nowMs = $this->clock->monotonicMs();
            if ($nowMs - $startMs >= $maxRuntime * 1000) {
                return $stopSeen = true;
            }
            if ($lastPoll !== null && ($nowMs - $lastPoll) < $this->stopPollSeconds * 1000) {
                return false;
            }
            $lastPoll = $nowMs;
            $fresh = ($this->settings)(true);
            if (!$fresh->enabled() || $fresh->isLanePaused($lane)) {
                return $stopSeen = true;
            }
            $current = $this->storage->getRuleState($rule->id);
            if ($current !== null && $current['stop_requested'] === 1) {
                return $stopSeen = true;
            }
            return false;
        };
        $heartbeat = function () use ($lockName, $owner, $maxRuntime, $grace): bool {
            return $this->locks->renew($lockName, $owner, Locks::ruleTtl($maxRuntime, $grace));
        };
        $context = new RunContext(
            $rule->id,
            $lane,
            $runId,
            $started,
            $started + $maxRuntime,
            $rule->args,
            \Closure::fromCallable($heartbeat),
            \Closure::fromCallable($shouldStop),
            $state['last_success_start'],
            $interval
        );

        $this->active = [
            'run_id' => $runId,
            'rule' => $rule,
            'state' => $state,
            'lane' => $lane,
            'owner' => $owner,
            'started' => $started,
            'interval' => $interval,
            'max_runtime' => $maxRuntime,
            'ob_level' => ob_get_level(),
            'context' => $context,
            'time' => $time,
            'start_ms' => $startMs,
        ];
        if ($this->registerShutdown && !$this->shutdownRegistered) {
            register_shutdown_function([$this, 'onShutdown']);
            $this->shutdownRegistered = true;
        }

        do_action('import_scheduler_run_started', $this->storage->getRun($runId) ?? ['id' => $runId, 'rule_id' => $rule->id]);

        ob_start();
        $result = null;
        try {
            $returned = $this->call($rule, $context);
            $result = self::mapResult($returned);
        } catch (\Throwable $e) {
            $result = RunResult::failure(Text::head(get_class($e) . ': ' . $e->getMessage(), self::MESSAGE_LIMIT));
        }
        $active = $this->current();
        $output = $active !== null ? $this->collectOutput($active['ob_level']) : '';

        if ($active === null || $active['run_id'] !== $runId) {
            // The shutdown handler already closed this run (tests simulate an exit this way).
            return $this->storage->getRun($runId);
        }
        $durationMs = (int) round($this->clock->monotonicMs() - $startMs);
        if ($result->message() === '' && trim($output) !== '') {
            $result = $result->withMessage(Text::head(trim($output), self::MESSAGE_LIMIT));
        }
        return $this->finish($active, $result, $durationMs);
    }

    /**
     * Shutdown handler: close a run that never returned (exit, fatal error)
     * as a failure and free what this process still owns. Public so the
     * engine can register it; not part of the API.
     *
     * @internal
     */
    public function onShutdown(): void
    {
        $active = $this->active;
        if ($active === null) {
            return;
        }
        try {
            $this->collectOutput($active['ob_level']);
            $error = ($this->lastError)();
            $message = 'runner exited';
            if ($error !== null && ($error['type'] & self::FATAL) !== 0) {
                $message = sprintf('PHP fatal error: %s in %s:%d', $error['message'], basename($error['file']), $error['line']);
            }
            $durationMs = (int) round($this->clock->monotonicMs() - $active['start_ms']);
            $this->finish($active, RunResult::failure(Text::head($message, self::MESSAGE_LIMIT)), $durationMs);
            if ($this->slot !== null) {
                $this->locks->release($this->slot['name'], $this->slot['owner']);
                $this->slot = null;
            }
        } catch (\Throwable $e) {
            Log::error(sprintf('could not close run %d of %s at shutdown: %s', $active['run_id'], $active['rule']->id, $e->getMessage()));
        }
        $this->active = null;
    }

    /**
     * The open run, if any. A method rather than a property read, because a
     * runner can close the run from inside (through the shutdown handler).
     *
     * @return Active|null
     */
    private function current(): ?array
    {
        return $this->active;
    }

    /**
     * Map whatever a runner returned to a result.
     *
     * @param mixed $returned
     */
    public static function mapResult($returned): RunResult
    {
        if ($returned instanceof RunResult) {
            return $returned->withMessage(Text::head($returned->message(), self::MESSAGE_LIMIT));
        }
        if (is_int($returned)) {
            return RunResult::success($returned);
        }
        if ($returned === null || $returned === true) {
            return RunResult::successUnknownCount();
        }
        if ($returned === false) {
            return RunResult::failure('runner returned false');
        }
        if (class_exists('WP_Error') && $returned instanceof \WP_Error) {
            $message = $returned->get_error_message();
            return RunResult::failure(Text::head($message !== '' ? $message : 'runner returned an error', self::MESSAGE_LIMIT));
        }
        return RunResult::successUnknownCount(sprintf('warning: runner returned an unexpected %s; treated as success', get_debug_type($returned)));
    }

    /**
     * @return mixed
     */
    private function call(Rule $rule, RunContext $context)
    {
        $runner = $rule->runner();
        if ($runner instanceof Adapter) {
            return $runner->run($context);
        }
        return $runner($context);
    }

    private function collectOutput(int $level): string
    {
        $output = '';
        while (ob_get_level() > $level) {
            $chunk = ob_get_clean();
            $output = (is_string($chunk) ? $chunk : '') . $output;
        }
        return $output;
    }

    /**
     * Close the run row, update state and next run, release the rule lock.
     *
     * @param Active $active
     * @return RunRow
     */
    private function finish(array $active, RunResult $result, int $durationMs): array
    {
        $this->active = null;
        $settings = ($this->settings)(false);
        $rule = $active['rule'];
        $state = $active['state'];
        $finished = max($active['started'], $active['time']->now());
        $overrun = $durationMs > $active['max_runtime'] * 1000 || $durationMs > $active['interval'] * 1000;
        $status = $overrun ? 'overrun' : $result->status();
        $original = $overrun ? $result->status() : '';
        $lockName = Locks::rule($rule->id);

        $owned = true;
        try {
            $owned = $this->locks->owns($lockName, $active['owner']);
        } catch (StorageException $e) {
            Log::error(sprintf('rule %s: could not check its lock after the run: %s', $rule->id, $e->getMessage()));
        }
        if (!$owned) {
            $original = $result->status();
            $status = 'lock_lost';
        }

        $run = [
            'id' => $active['run_id'],
            'rule_id' => $rule->id,
            'lane' => $active['lane'],
            'started' => $active['started'],
            'finished' => $finished,
            'duration_ms' => $durationMs,
            'status' => $status,
            'original_status' => $original,
            'items' => $result->items(),
            'message' => $result->message(),
            'log' => $active['context']->logText(),
            'owner' => $active['owner'],
        ];

        try {
            $this->storage->updateRun($active['run_id'], [
                'finished' => $finished,
                'duration_ms' => $durationMs,
                'status' => $status,
                'original_status' => $original,
                'items' => $result->items(),
                'message' => $result->message(),
                'log' => $run['log'],
            ]);
            if ($owned) {
                $this->updateState($rule, $state, $active, $result, $status, $finished, $durationMs, $settings);
                $this->locks->release($lockName, $active['owner']);
            } else {
                $this->storage->updateRuleState($rule->id, [
                    'last_status' => 'lock_lost',
                    'last_message' => Text::head('lock lost; result was ' . $result->status() . ($result->message() !== '' ? ': ' . $result->message() : ''), self::MESSAGE_LIMIT),
                    'updated' => $finished,
                ]);
            }
        } catch (StorageException $e) {
            Log::error(sprintf('rule %s: could not record the run (%s, %s): %s', $rule->id, $status, $result->message(), $e->getMessage()));
        }

        if ($status === 'lock_lost') {
            Log::error(sprintf('rule %s: run %d lost its lock; recorded as lock_lost', $rule->id, $active['run_id']));
        } elseif ($result->status() === RunResult::FAILURE) {
            Log::error(sprintf('rule %s: run failed: %s', $rule->id, $result->message()));
        }

        do_action('import_scheduler_run_finished', $run);
        return $run;
    }

    /**
     * @param StateRow $state
     * @param Active   $active
     */
    private function updateState(
        Rule $rule,
        array $state,
        array $active,
        RunResult $result,
        string $status,
        int $finished,
        int $durationMs,
        Settings $settings
    ): void {
        $interval = $active['interval'];
        $failures = $state['consecutive_failures'];
        $overruns = $state['consecutive_overruns'];
        $fields = [
            'last_finish' => $finished,
            'last_duration_ms' => $durationMs,
            'last_status' => $status,
            'last_items' => $result->items(),
            'last_message' => $result->message(),
            'running_run_id' => null,
            'stop_requested' => 0,
            'updated' => $finished,
        ];
        if ($result->isSuccess()) {
            $failures = 0;
            $fields['last_success_start'] = $active['started'];
        } else {
            $failures++;
        }
        $overruns = $status === 'overrun' ? $overruns + 1 : 0;
        $fields['consecutive_failures'] = $failures;
        $fields['consecutive_overruns'] = $overruns;

        if ($status === 'overrun') {
            $fields['next_run'] = $this->nextRun->afterOverrun($finished, $interval, $rule->jitter);
        } elseif (!$result->isSuccess()) {
            $fields['next_run'] = $this->nextRun->afterFailure($finished, $interval, $failures, $settings->int('backoff_cap'), $rule->jitter);
        } else {
            $fields['next_run'] = $this->nextRun->afterSuccess($state['next_run'], $finished, $interval, $rule->jitter);
        }

        $pauseAfter = $settings->int('pause_after_failures');
        $pause = !$result->isSuccess() && $pauseAfter > 0 && $failures >= $pauseAfter && $state['paused'] === 0;
        if ($pause) {
            $fields['paused'] = 1;
            $fields['pause_reason'] = 'too many failures';
        }
        $this->storage->updateRuleState($rule->id, $fields, ['running_run_id' => $active['run_id']]);
        if ($pause) {
            Log::error(sprintf('rule %s paused after %d consecutive failures', $rule->id, $failures));
            do_action('import_scheduler_rule_paused', $rule->id, 'too many failures');
        }
    }
}
