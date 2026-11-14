<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Handed to a runner for one run.
 *
 * Check shouldStop() between items and return what you have when it is
 * true. Call heartbeat() in long loops to keep the rule lock; it returns
 * false when the lock was lost.
 */
final class RunContext
{
    public const LOG_LIMIT = 2048;

    private string $log = '';

    /** @var \Closure(): bool */
    private \Closure $heartbeat;

    /** @var \Closure(): bool */
    private \Closure $shouldStop;

    /**
     * @param array<string, mixed> $args
     * @param \Closure(): bool     $heartbeat
     * @param \Closure(): bool     $shouldStop
     */
    public function __construct(
        private string $ruleId,
        private string $lane,
        private int $runId,
        private int $startedAt,
        private int $deadline,
        private array $args,
        \Closure $heartbeat,
        \Closure $shouldStop,
        private ?int $lastSuccessAt = null,
        private int $interval = 0
    ) {
        $this->heartbeat = $heartbeat;
        $this->shouldStop = $shouldStop;
    }

    public function ruleId(): string
    {
        return $this->ruleId;
    }

    public function lane(): string
    {
        return $this->lane;
    }

    public function runId(): int
    {
        return $this->runId;
    }

    /** Start time, UTC Unix seconds from the database clock. */
    public function startedAt(): int
    {
        return $this->startedAt;
    }

    /** Start plus max runtime. */
    public function deadline(): int
    {
        return $this->deadline;
    }

    /** @return array<string, mixed> */
    public function args(): array
    {
        return $this->args;
    }

    /** Start of the rule's last successful run, or null. */
    public function lastSuccessAt(): ?int
    {
        return $this->lastSuccessAt;
    }

    /** The rule's effective interval in seconds. */
    public function interval(): int
    {
        return $this->interval;
    }

    public function heartbeat(): bool
    {
        return ($this->heartbeat)();
    }

    public function shouldStop(): bool
    {
        return ($this->shouldStop)();
    }

    /**
     * Keep a line in the run's log (the last 2 KB are stored).
     */
    public function log(string $level, string $message): void
    {
        $level = preg_replace('/[^a-z]/', '', strtolower($level));
        $line = '[' . ($level === '' || $level === null ? 'info' : $level) . '] ' . Text::oneLine($message) . "\n";
        $this->log = Text::tail($this->log . $line, self::LOG_LIMIT);
    }

    /** @internal */
    public function logText(): string
    {
        return $this->log;
    }
}
