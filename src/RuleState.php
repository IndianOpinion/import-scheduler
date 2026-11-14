<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Read-only snapshot of one rule: the stored state row with the registered
 * definition and operator overrides applied.
 *
 * @phpstan-import-type StateRow from \IndianOpinion\ImportScheduler\Storage\Storage
 */
final class RuleState
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_DUE = 'due';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_FAILING = 'failing';
    public const STATUS_SLOW = 'consistently slow';
    public const STATUS_ORPHAN = 'orphan';

    public const SLOW_AFTER = 3;

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $registered,
        public readonly string $lane,
        public readonly ?string $laneOverride,
        public readonly ?string $registeredLane,
        public readonly int $interval,
        public readonly ?int $intervalOverride,
        public readonly ?int $registeredInterval,
        public readonly int $maxRuntime,
        public readonly bool $paused,
        public readonly string $pauseReason,
        public readonly ?int $nextRun,
        public readonly ?int $lastStart,
        public readonly ?int $lastFinish,
        public readonly ?int $lastDurationMs,
        public readonly string $lastStatus,
        public readonly ?int $lastItems,
        public readonly string $lastMessage,
        public readonly int $consecutiveFailures,
        public readonly int $consecutiveOverruns,
        public readonly ?int $lastSuccessStart,
        public readonly bool $stopRequested,
        public readonly ?int $runningRunId,
        public readonly bool $tickOrphan,
        public readonly ?int $firstSeen,
        public readonly ?int $updated
    ) {
    }

    /**
     * @param StateRow|null $row
     */
    public static function from(?Rule $rule, ?array $row, Settings $settings): ?self
    {
        if ($rule === null && $row === null) {
            return null;
        }
        $intervalOverride = $row['interval_override'] ?? null;
        $laneOverride = $row['lane_override'] ?? null;
        $interval = $intervalOverride ?? ($rule !== null ? $rule->interval : Rule::MIN_INTERVAL);
        $lane = $laneOverride ?? ($rule !== null ? $rule->lane : ($row['lane'] ?? Rule::DEFAULT_LANE));
        $maxRuntime = $rule !== null
            ? $rule->effectiveMaxRuntime($interval, $settings->int('default_max_runtime'))
            : $settings->int('default_max_runtime');
        return new self(
            $rule !== null ? $rule->id : (string) ($row['rule_id'] ?? ''),
            $rule !== null ? $rule->label : (string) ($row['rule_id'] ?? ''),
            $rule !== null,
            $lane,
            $laneOverride,
            $rule?->lane,
            $interval,
            $intervalOverride,
            $rule?->interval,
            $maxRuntime,
            (bool) ($row['paused'] ?? 0),
            (string) ($row['pause_reason'] ?? ''),
            $row['next_run'] ?? null,
            $row['last_start'] ?? null,
            $row['last_finish'] ?? null,
            $row['last_duration_ms'] ?? null,
            (string) ($row['last_status'] ?? ''),
            $row['last_items'] ?? null,
            (string) ($row['last_message'] ?? ''),
            (int) ($row['consecutive_failures'] ?? 0),
            (int) ($row['consecutive_overruns'] ?? 0),
            $row['last_success_start'] ?? null,
            (bool) ($row['stop_requested'] ?? 0),
            $row['running_run_id'] ?? null,
            (bool) ($row['tick_orphan'] ?? 0),
            $row['first_seen'] ?? null,
            $row['updated'] ?? null
        );
    }

    /**
     * The one status the screen shows, most urgent first.
     */
    public function status(int $now): string
    {
        if (!$this->registered) {
            return self::STATUS_ORPHAN;
        }
        if ($this->paused) {
            return self::STATUS_PAUSED;
        }
        if ($this->runningRunId !== null) {
            return self::STATUS_RUNNING;
        }
        if ($this->consecutiveFailures > 0) {
            return self::STATUS_FAILING;
        }
        if ($this->consecutiveOverruns >= self::SLOW_AFTER) {
            return self::STATUS_SLOW;
        }
        if ($this->nextRun === null || $this->nextRun <= $now) {
            return self::STATUS_DUE;
        }
        return self::STATUS_IDLE;
    }

    public function isConsistentlySlow(): bool
    {
        return $this->consecutiveOverruns >= self::SLOW_AFTER;
    }

    public function lockLost(): bool
    {
        return $this->lastStatus === 'lock_lost';
    }
}
