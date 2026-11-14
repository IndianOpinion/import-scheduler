<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Support;

use IndianOpinion\ImportScheduler\Exception\SchemaException;
use IndianOpinion\ImportScheduler\Exception\StorageException;
use IndianOpinion\ImportScheduler\Storage\Storage;

/**
 * In-memory double of the SQL storage. Honours the primary keys and the
 * conditional updates the SQL storage relies on, compares lock times with
 * the database's own clock, and counts statements.
 */
final class MemoryStorage implements Storage
{
    public function __construct(public MemoryDatabase $db)
    {
    }

    private function q(bool $tables = true): void
    {
        if ($this->db->down) {
            throw new StorageException('connection refused');
        }
        if ($tables && $this->db->tablesMissing) {
            throw new SchemaException("Table 'wp_import_scheduler_locks' doesn't exist");
        }
        $this->db->queries++;
    }

    public function now(): int
    {
        $this->q(false);
        return $this->db->now();
    }

    public function insertLock(string $name, string $owner, int $ttl): bool
    {
        $this->q();
        if (isset($this->db->locks[$name])) {
            return false;
        }
        $now = $this->db->now();
        $this->db->locks[$name] = ['name' => $name, 'owner' => $owner, 'acquired' => $now, 'expires' => $now + $ttl, 'heartbeat' => $now];
        return true;
    }

    public function takeExpiredLock(string $name, string $owner, int $ttl): bool
    {
        $this->q();
        $now = $this->db->now();
        $lock = $this->db->locks[$name] ?? null;
        if ($lock === null || $lock['expires'] >= $now) {
            return false;
        }
        $this->db->locks[$name] = ['name' => $name, 'owner' => $owner, 'acquired' => $now, 'expires' => $now + $ttl, 'heartbeat' => $now];
        return true;
    }

    public function renewLock(string $name, string $owner, int $ttl): bool
    {
        $this->q();
        $lock = $this->db->locks[$name] ?? null;
        if ($lock === null || $lock['owner'] !== $owner) {
            return false;
        }
        $now = $this->db->now();
        $this->db->locks[$name]['expires'] = $now + $ttl;
        $this->db->locks[$name]['heartbeat'] = $now;
        return true;
    }

    public function releaseLock(string $name, string $owner): bool
    {
        $this->q();
        $lock = $this->db->locks[$name] ?? null;
        if ($lock === null || $lock['owner'] !== $owner) {
            return false;
        }
        unset($this->db->locks[$name]);
        return true;
    }

    public function deleteExpiredLock(string $name): bool
    {
        $this->q();
        $lock = $this->db->locks[$name] ?? null;
        if ($lock === null || $lock['expires'] >= $this->db->now()) {
            return false;
        }
        unset($this->db->locks[$name]);
        return true;
    }

    public function deleteLock(string $name): bool
    {
        $this->q();
        if (!isset($this->db->locks[$name])) {
            return false;
        }
        unset($this->db->locks[$name]);
        return true;
    }

    public function getLock(string $name): ?array
    {
        $this->q();
        /** @var array{name: string, owner: string, acquired: int, expires: int, heartbeat: int}|null */
        return $this->db->locks[$name] ?? null;
    }

    public function allLocks(): array
    {
        $this->q();
        $locks = $this->db->locks;
        ksort($locks);
        /** @var list<array{name: string, owner: string, acquired: int, expires: int, heartbeat: int}> */
        return array_values($locks);
    }

    public function allRuleStates(): array
    {
        $this->q();
        /** @phpstan-ignore return.type */
        return $this->db->rules;
    }

    public function getRuleState(string $ruleId): ?array
    {
        $this->q();
        /** @phpstan-ignore return.type */
        return $this->db->rules[$ruleId] ?? null;
    }

    public function insertRuleStates(array $rows): void
    {
        $this->q();
        foreach ($rows as $row) {
            if (!isset($this->db->rules[$row['rule_id']])) {
                $this->db->rules[$row['rule_id']] = $row;
            }
        }
    }

    public function updateRuleState(string $ruleId, array $fields, array $where = []): void
    {
        $this->q();
        $row = $this->db->rules[$ruleId] ?? null;
        if ($row === null) {
            return;
        }
        foreach ($where as $column => $value) {
            if (($row[$column] ?? null) !== $value) {
                return;
            }
        }
        $this->db->rules[$ruleId] = array_merge($row, $fields);
    }

    public function deleteRuleState(string $ruleId): bool
    {
        $this->q();
        if (!isset($this->db->rules[$ruleId])) {
            return false;
        }
        unset($this->db->rules[$ruleId]);
        return true;
    }

    public function dueRuleStates(string $lane, int $now, int $limit): array
    {
        $this->q();
        $due = array_filter(
            $this->db->rules,
            static fn (array $r): bool => $r['lane'] === $lane && $r['paused'] === 0 && $r['tick_orphan'] === 0 && $r['next_run'] <= $now
        );
        usort($due, static fn (array $a, array $b): int => [$a['next_run'], $a['rule_id']] <=> [$b['next_run'], $b['rule_id']]);
        /** @phpstan-ignore return.type */
        return array_slice($due, 0, $limit);
    }

    public function insertRun(array $row): int
    {
        $this->q();
        $id = ++$this->db->runSeq;
        $this->db->runs[$id] = array_merge([
            'id' => $id, 'rule_id' => '', 'lane' => '', 'started' => 0, 'finished' => null, 'duration_ms' => null,
            'status' => 'running', 'original_status' => '', 'items' => null, 'message' => '', 'log' => '', 'owner' => '',
        ], $row, ['id' => $id]);
        return $id;
    }

    public function updateRun(int $id, array $fields, array $where = []): bool
    {
        $this->q();
        $row = $this->db->runs[$id] ?? null;
        if ($row === null) {
            return false;
        }
        foreach ($where as $column => $value) {
            if (($row[$column] ?? null) !== $value) {
                return false;
            }
        }
        $this->db->runs[$id] = array_merge($row, $fields);
        return true;
    }

    public function getRun(int $id): ?array
    {
        $this->q();
        /** @phpstan-ignore return.type */
        return $this->db->runs[$id] ?? null;
    }

    public function runsForRule(string $ruleId, int $limit): array
    {
        $this->q();
        $runs = array_values(array_filter($this->db->runs, static fn (array $r): bool => $r['rule_id'] === $ruleId));
        usort($runs, static fn (array $a, array $b): int => [$b['started'], $b['id']] <=> [$a['started'], $a['id']]);
        /** @phpstan-ignore return.type */
        return array_slice($runs, 0, $limit);
    }

    public function recentDurations(array $ruleIds, int $perRule): array
    {
        $this->q();
        $out = [];
        $runs = $this->db->runs;
        krsort($runs);
        foreach ($runs as $run) {
            $id = (string) $run['rule_id'];
            if (!in_array($id, $ruleIds, true) || $run['duration_ms'] === null) {
                continue;
            }
            if (count($out[$id] ?? []) < $perRule) {
                $out[$id][] = (int) $run['duration_ms'];
            }
        }
        return $out;
    }

    public function deleteRunsForRule(string $ruleId): int
    {
        $this->q();
        $n = 0;
        foreach ($this->db->runs as $id => $run) {
            if ($run['rule_id'] === $ruleId) {
                unset($this->db->runs[$id]);
                $n++;
            }
        }
        return $n;
    }

    public function pruneRuns(int $keep, int $cutoff, int $limit, bool $dryRun = false): int
    {
        $this->q();
        $doomed = [];
        $byRule = [];
        foreach ($this->db->runs as $id => $run) {
            if ($run['started'] < $cutoff && $run['status'] !== 'running') {
                $doomed[$id] = true;
            }
            $byRule[(string) $run['rule_id']][] = $id;
        }
        foreach ($byRule as $ids) {
            rsort($ids);
            foreach (array_slice($ids, $keep) as $id) {
                $doomed[$id] = true;
            }
        }
        $doomed = array_slice(array_keys($doomed), 0, $dryRun ? PHP_INT_MAX : $limit);
        if (!$dryRun) {
            foreach ($doomed as $id) {
                unset($this->db->runs[$id]);
            }
        }
        return count($doomed);
    }

    public function insertTick(array $row): void
    {
        $this->q();
        $id = ++$this->db->tickSeq;
        $this->db->ticks[$id] = array_merge(['id' => $id, 'slot' => 0, 'source' => 'cli', 'reason' => '', 'runs' => 0, 'failures' => 0], $row, ['id' => $id]);
    }

    public function latestTicks(): array
    {
        $this->q();
        $out = [];
        foreach ($this->db->ticks as $tick) {
            $out[(string) $tick['lane']] = $tick;
        }
        ksort($out);
        /** @phpstan-ignore return.type */
        return $out;
    }

    public function lastTickStart(string $lane, string $source): ?int
    {
        $this->q();
        $latest = null;
        foreach ($this->db->ticks as $tick) {
            if ($tick['lane'] === $lane && $tick['source'] === $source) {
                $latest = max($latest ?? 0, (int) $tick['started']);
            }
        }
        return $latest;
    }

    public function pruneTicks(int $cutoff, int $limit, bool $dryRun = false): int
    {
        $this->q();
        $n = 0;
        foreach ($this->db->ticks as $id => $tick) {
            if ($tick['started'] < $cutoff && $n < $limit) {
                $n++;
                if (!$dryRun) {
                    unset($this->db->ticks[$id]);
                }
            }
        }
        return $n;
    }
}
