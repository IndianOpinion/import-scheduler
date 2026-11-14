<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

use IndianOpinion\ImportScheduler\Exception\InvalidRuleException;

/**
 * A rule as registered in code. Immutable. Operator overrides live in the
 * database and are applied on top of this (see RuleState).
 */
final class Rule
{
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
    public const LANE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';
    public const MIN_INTERVAL = 60;
    public const MAX_INTERVAL = 2592000;
    public const MIN_RUNTIME = 10;
    public const MAX_RUNTIME = 21600;
    public const DEFAULT_LANE = 'default';

    /** @var callable|Adapter */
    private $runner;

    /**
     * @param callable|Adapter     $runner
     * @param array<string, mixed> $args
     */
    private function __construct(
        public readonly string $id,
        $runner,
        public readonly string $label,
        public readonly int $interval,
        public readonly string $lane,
        public readonly ?int $maxRuntime,
        public readonly int $startDelay,
        public readonly int $jitter,
        public readonly array $args
    ) {
        $this->runner = $runner;
    }

    /**
     * Validate and build a rule. Throws on anything out of range: values are
     * rejected, never clamped, so a typo never becomes a different schedule.
     *
     * @param mixed                $runner
     * @param array<string, mixed> $args
     * @throws InvalidRuleException
     */
    public static function fromArgs(string $id, $runner, array $args): self
    {
        if (!self::isValidId($id)) {
            throw new InvalidRuleException(sprintf('Invalid rule id "%s": use 1 to 64 of a-z, 0-9, _ and -, starting with a letter or digit.', $id));
        }
        if (!$runner instanceof Adapter && !is_callable($runner)) {
            throw new InvalidRuleException(sprintf('Rule "%s": the runner is not callable and does not implement Adapter.', $id));
        }
        $known = ['label', 'interval', 'lane', 'max_runtime', 'start_delay', 'jitter', 'args'];
        foreach (array_keys($args) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidRuleException(sprintf('Rule "%s": unknown argument "%s".', $id, (string) $key));
            }
        }
        if (!isset($args['interval'])) {
            throw new InvalidRuleException(sprintf('Rule "%s": interval is required.', $id));
        }
        $interval = self::intArg($id, 'interval', $args['interval']);
        if ($interval < self::MIN_INTERVAL || $interval > self::MAX_INTERVAL) {
            throw new InvalidRuleException(sprintf('Rule "%s": interval %d is outside 60 to 2592000 seconds.', $id, $interval));
        }
        $lane = $args['lane'] ?? self::DEFAULT_LANE;
        if (!is_string($lane) || !self::isValidLane($lane)) {
            throw new InvalidRuleException(sprintf('Rule "%s": invalid lane name.', $id));
        }
        $maxRuntime = null;
        if (isset($args['max_runtime'])) {
            $maxRuntime = self::intArg($id, 'max_runtime', $args['max_runtime']);
            if ($maxRuntime < self::MIN_RUNTIME || $maxRuntime > self::MAX_RUNTIME) {
                throw new InvalidRuleException(sprintf('Rule "%s": max_runtime %d is outside 10 to 21600 seconds.', $id, $maxRuntime));
            }
        }
        $startDelay = isset($args['start_delay']) ? self::intArg($id, 'start_delay', $args['start_delay']) : 0;
        if ($startDelay < 0 || $startDelay > self::MAX_INTERVAL) {
            throw new InvalidRuleException(sprintf('Rule "%s": start_delay must be 0 to 2592000 seconds.', $id));
        }
        $jitter = isset($args['jitter']) ? self::intArg($id, 'jitter', $args['jitter']) : 0;
        if ($jitter < 0 || $jitter > intdiv($interval, 4)) {
            throw new InvalidRuleException(sprintf('Rule "%s": jitter must be 0 to a quarter of the interval.', $id));
        }
        $label = $args['label'] ?? null;
        if ($label === null && $runner instanceof DescribesItself) {
            $label = $runner->label();
        }
        if ($label !== null && !is_string($label)) {
            throw new InvalidRuleException(sprintf('Rule "%s": label must be a string.', $id));
        }
        $label = ($label === null || trim($label) === '') ? $id : trim($label);
        $runnerArgs = $args['args'] ?? [];
        if (!is_array($runnerArgs)) {
            throw new InvalidRuleException(sprintf('Rule "%s": args must be an array.', $id));
        }
        /** @var array<string, mixed> $runnerArgs */
        return new self($id, $runner, $label, $interval, $lane, $maxRuntime, $startDelay, $jitter, $runnerArgs);
    }

    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    public static function isValidLane(string $lane): bool
    {
        return preg_match(self::LANE_PATTERN, $lane) === 1;
    }

    /**
     * @return callable|Adapter
     */
    public function runner()
    {
        return $this->runner;
    }

    /**
     * Max runtime for a given effective interval: the registered value, else
     * the site default, or the interval when that is shorter.
     */
    public function effectiveMaxRuntime(int $interval, int $defaultMaxRuntime): int
    {
        if ($this->maxRuntime !== null) {
            return $this->maxRuntime;
        }
        return max(self::MIN_RUNTIME, min($defaultMaxRuntime, $interval));
    }

    /**
     * @param mixed $value
     */
    private static function intArg(string $id, string $name, $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw new InvalidRuleException(sprintf('Rule "%s": %s must be an integer number of seconds.', $id, $name));
    }
}
