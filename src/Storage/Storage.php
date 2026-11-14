<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Storage;

use IndianOpinion\ImportScheduler\Exception\StorageException;

/**
 * Everything the scheduler persists. One SQL implementation ships; the test
 * suite uses an in-memory double with the same contract.
 *
 * Lock methods compare times with the storage's own clock, never PHP's.
 * Every method throws StorageException (or SchemaException when the tables
 * are missing) instead of failing silently.
 *
 * @phpstan-type StateRow array{rule_id: string, lane: string, lane_override: ?string, interval_override: ?int, paused: int, pause_reason: string, next_run: int, last_start: ?int, last_finish: ?int, last_duration_ms: ?int, last_status: string, last_items: ?int, last_message: string, consecutive_failures: int, consecutive_overruns: int, last_success_start: ?int, stop_requested: int, running_run_id: ?int, tick_orphan: int, first_seen: int, updated: int}
 * @phpstan-type RunRow array{id: int, rule_id: string, lane: string, started: int, finished: ?int, duration_ms: ?int, status: string, original_status: string, items: ?int, message: string, log: string, owner: string}
 * @phpstan-type LockRow array{name: string, owner: string, acquired: int, expires: int, heartbeat: int}
 * @phpstan-type TickRow array{id: int, lane: string, slot: int, source: string, started: int, finished: int, outcome: string, reason: string, runs: int, failures: int}
 */
interface Storage
{
    /**
     * The storage clock, UTC Unix seconds.
     *
     * @throws StorageException When the database cannot be reached.
     */
    public function now(): int;

    /** Insert a lock row; false when the name is taken. */
    public function insertLock(string $name, string $owner, int $ttl): bool;

    /** Take a lock over in one conditional statement, only if it has expired. */
    public function takeExpiredLock(string $name, string $owner, int $ttl): bool;

    /** Extend expiry and heartbeat; false when the lock is not ours. */
    public function renewLock(string $name, string $owner, int $ttl): bool;

    /** Delete when the owner matches; false otherwise. */
    public function releaseLock(string $name, string $owner): bool;

    /** Delete only if expired by the storage clock. */
    public function deleteExpiredLock(string $name): bool;

    /** Delete whatever holds it. */
    public function deleteLock(string $name): bool;

    /** @return LockRow|null */
    public function getLock(string $name): ?array;

    /** @return list<LockRow> */
    public function allLocks(): array;

    /** @return array<string, StateRow> Keyed by rule id. */
    public function allRuleStates(): array;

    /** @return StateRow|null */
    public function getRuleState(string $ruleId): ?array;

    /**
     * Insert state rows; ids that already exist are left alone.
     *
     * @param list<StateRow> $rows
     */
    public function insertRuleStates(array $rows): void;

    /**
     * Update columns of one state row.
     *
     * @param array<string, int|string|null> $fields
     * @param array<string, int|string|null> $where Extra equality conditions.
     */
    public function updateRuleState(string $ruleId, array $fields, array $where = []): void;

    public function deleteRuleState(string $ruleId): bool;

    /**
     * Due rows for a lane: not paused, not orphaned in the last tick, next run
     * at or before $now, ordered by next run then rule id.
     *
     * @return list<StateRow>
     */
    public function dueRuleStates(string $lane, int $now, int $limit): array;

    /** @param array<string, int|string|null> $row */
    public function insertRun(array $row): int;

    /**
     * @param array<string, int|string|null> $fields
     * @param array<string, int|string|null> $where Extra equality conditions.
     * @return bool True when a row changed.
     */
    public function updateRun(int $id, array $fields, array $where = []): bool;

    /** @return RunRow|null */
    public function getRun(int $id): ?array;

    /** @return list<RunRow> Newest first. */
    public function runsForRule(string $ruleId, int $limit): array;

    /**
     * Durations of finished runs, newest first, at most $perRule per rule.
     *
     * @param list<string> $ruleIds
     * @return array<string, list<int>>
     */
    public function recentDurations(array $ruleIds, int $perRule): array;

    public function deleteRunsForRule(string $ruleId): int;

    /**
     * Remove runs beyond the newest $keep per rule and runs started before
     * $cutoff, at most $limit rows. With $dryRun only count them.
     */
    public function pruneRuns(int $keep, int $cutoff, int $limit, bool $dryRun = false): int;

    /** @param array<string, int|string> $row */
    public function insertTick(array $row): void;

    /** @return array<string, TickRow> Latest tick per lane. */
    public function latestTicks(): array;

    /** Start time of the latest tick from $source on $lane, or null. */
    public function lastTickStart(string $lane, string $source): ?int;

    public function pruneTicks(int $cutoff, int $limit, bool $dryRun = false): int;
}
