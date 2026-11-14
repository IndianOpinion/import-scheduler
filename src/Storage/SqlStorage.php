<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Storage;

use IndianOpinion\ImportScheduler\Exception\StorageException;

/**
 * Storage on MySQL or MariaDB. Every lock statement compares times with
 * UNIX_TIMESTAMP() inside the statement, so web nodes with skewed clocks
 * agree. A takeover is one conditional UPDATE: no read-then-write gap.
 *
 * @phpstan-import-type StateRow from Storage
 * @phpstan-import-type RunRow from Storage
 * @phpstan-import-type LockRow from Storage
 * @phpstan-import-type TickRow from Storage
 */
final class SqlStorage implements Storage
{
    private const INT_COLUMNS = [
        'interval_override', 'paused', 'next_run', 'last_start', 'last_finish', 'last_duration_ms', 'last_items',
        'consecutive_failures', 'consecutive_overruns', 'last_success_start', 'stop_requested', 'running_run_id',
        'tick_orphan', 'first_seen', 'updated', 'id', 'started', 'finished', 'duration_ms', 'items', 'acquired',
        'expires', 'heartbeat', 'slot', 'runs', 'failures',
    ];

    public function __construct(private Connection $db)
    {
    }

    public function now(): int
    {
        $rows = $this->db->select('SELECT UNIX_TIMESTAMP() AS now');
        $now = $rows[0]['now'] ?? null;
        if (!is_numeric($now)) {
            throw new StorageException('The database did not return its clock.');
        }
        return (int) $now;
    }

    public function insertLock(string $name, string $owner, int $ttl): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO ' . $this->t(Schema::LOCKS) . ' (name, owner, acquired, expires, heartbeat)'
            . ' VALUES (%s, %s, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() + %d, UNIX_TIMESTAMP())',
            [$name, $owner, $ttl]
        ) === 1;
    }

    public function takeExpiredLock(string $name, string $owner, int $ttl): bool
    {
        return $this->db->execute(
            'UPDATE ' . $this->t(Schema::LOCKS) . ' SET owner = %s, acquired = UNIX_TIMESTAMP(),'
            . ' expires = UNIX_TIMESTAMP() + %d, heartbeat = UNIX_TIMESTAMP()'
            . ' WHERE name = %s AND expires < UNIX_TIMESTAMP()',
            [$owner, $ttl, $name]
        ) === 1;
    }

    public function renewLock(string $name, string $owner, int $ttl): bool
    {
        // MySQL reports 0 affected rows when nothing changed, so a renew in the
        // same second as the take would look lost. Count matched rows instead.
        $this->db->execute(
            'UPDATE ' . $this->t(Schema::LOCKS) . ' SET expires = UNIX_TIMESTAMP() + %d, heartbeat = UNIX_TIMESTAMP()'
            . ' WHERE name = %s AND owner = %s',
            [$ttl, $name, $owner]
        );
        $lock = $this->getLock($name);
        return $lock !== null && $lock['owner'] === $owner;
    }

    public function releaseLock(string $name, string $owner): bool
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->t(Schema::LOCKS) . ' WHERE name = %s AND owner = %s',
            [$name, $owner]
        ) === 1;
    }

    public function deleteExpiredLock(string $name): bool
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->t(Schema::LOCKS) . ' WHERE name = %s AND expires < UNIX_TIMESTAMP()',
            [$name]
        ) === 1;
    }

    public function deleteLock(string $name): bool
    {
        return $this->db->execute('DELETE FROM ' . $this->t(Schema::LOCKS) . ' WHERE name = %s', [$name]) === 1;
    }

    public function getLock(string $name): ?array
    {
        $rows = $this->db->select('SELECT * FROM ' . $this->t(Schema::LOCKS) . ' WHERE name = %s', [$name]);
        return $rows === [] ? null : $this->lockRow($rows[0]);
    }

    public function allLocks(): array
    {
        $rows = $this->db->select('SELECT * FROM ' . $this->t(Schema::LOCKS) . ' ORDER BY name');
        return array_map(fn (array $row): array => $this->lockRow($row), $rows);
    }

    public function allRuleStates(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT * FROM ' . $this->t(Schema::RULES)) as $row) {
            $state = $this->stateRow($row);
            $out[$state['rule_id']] = $state;
        }
        return $out;
    }

    public function getRuleState(string $ruleId): ?array
    {
        $rows = $this->db->select('SELECT * FROM ' . $this->t(Schema::RULES) . ' WHERE rule_id = %s', [$ruleId]);
        return $rows === [] ? null : $this->stateRow($rows[0]);
    }

    public function insertRuleStates(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $columns = Schema::COLUMNS[Schema::RULES];
        foreach (array_chunk($rows, 100) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $row) {
                $parts = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    if ($value === null) {
                        $parts[] = 'NULL';
                        continue;
                    }
                    $parts[] = is_int($value) ? '%d' : '%s';
                    $params[] = $value;
                }
                $tuples[] = '(' . implode(', ', $parts) . ')';
            }
            $this->db->execute(
                'INSERT IGNORE INTO ' . $this->t(Schema::RULES) . ' (' . implode(', ', $columns) . ') VALUES '
                . implode(', ', $tuples),
                $params
            );
        }
    }

    public function updateRuleState(string $ruleId, array $fields, array $where = []): void
    {
        if ($fields === []) {
            return;
        }
        [$set, $params] = $this->assignments($fields, Schema::RULES);
        [$cond, $condParams] = $this->conditions(['rule_id' => $ruleId] + $where, Schema::RULES);
        $this->db->execute('UPDATE ' . $this->t(Schema::RULES) . ' SET ' . $set . ' WHERE ' . $cond, array_merge($params, $condParams));
    }

    public function deleteRuleState(string $ruleId): bool
    {
        return $this->db->execute('DELETE FROM ' . $this->t(Schema::RULES) . ' WHERE rule_id = %s', [$ruleId]) === 1;
    }

    public function dueRuleStates(string $lane, int $now, int $limit): array
    {
        $rows = $this->db->select(
            'SELECT * FROM ' . $this->t(Schema::RULES)
            . ' WHERE lane = %s AND paused = 0 AND tick_orphan = 0 AND next_run <= %d'
            . ' ORDER BY next_run ASC, rule_id ASC LIMIT %d',
            [$lane, $now, max(1, $limit)]
        );
        return array_map(fn (array $row): array => $this->stateRow($row), $rows);
    }

    public function insertRun(array $row): int
    {
        $columns = [];
        $parts = [];
        $params = [];
        foreach ($row as $column => $value) {
            $this->assertColumn(Schema::RUNS, $column);
            $columns[] = $column;
            if ($value === null) {
                $parts[] = 'NULL';
                continue;
            }
            $parts[] = is_int($value) ? '%d' : '%s';
            $params[] = $value;
        }
        $this->db->execute(
            'INSERT INTO ' . $this->t(Schema::RUNS) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $parts) . ')',
            $params
        );
        $id = $this->db->lastInsertId();
        if ($id <= 0) {
            throw new StorageException('Could not record the run.');
        }
        return $id;
    }

    public function updateRun(int $id, array $fields, array $where = []): bool
    {
        [$set, $params] = $this->assignments($fields, Schema::RUNS);
        [$cond, $condParams] = $this->conditions(['id' => $id] + $where, Schema::RUNS);
        return $this->db->execute('UPDATE ' . $this->t(Schema::RUNS) . ' SET ' . $set . ' WHERE ' . $cond, array_merge($params, $condParams)) > 0;
    }

    public function getRun(int $id): ?array
    {
        $rows = $this->db->select('SELECT * FROM ' . $this->t(Schema::RUNS) . ' WHERE id = %d', [$id]);
        return $rows === [] ? null : $this->runRow($rows[0]);
    }

    public function runsForRule(string $ruleId, int $limit): array
    {
        $rows = $this->db->select(
            'SELECT * FROM ' . $this->t(Schema::RUNS) . ' WHERE rule_id = %s ORDER BY started DESC, id DESC LIMIT %d',
            [$ruleId, max(1, $limit)]
        );
        return array_map(fn (array $row): array => $this->runRow($row), $rows);
    }

    public function recentDurations(array $ruleIds, int $perRule): array
    {
        $out = [];
        if ($ruleIds === []) {
            return $out;
        }
        foreach (array_chunk($ruleIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
            $rows = $this->db->select(
                'SELECT rule_id, duration_ms FROM ' . $this->t(Schema::RUNS)
                . ' WHERE rule_id IN (' . $placeholders . ') AND duration_ms IS NOT NULL ORDER BY rule_id, id DESC',
                array_values($chunk)
            );
            foreach ($rows as $row) {
                $id = (string) $row['rule_id'];
                if (count($out[$id] ?? []) < $perRule) {
                    $out[$id][] = (int) $row['duration_ms'];
                }
            }
        }
        return $out;
    }

    public function deleteRunsForRule(string $ruleId): int
    {
        return $this->db->execute('DELETE FROM ' . $this->t(Schema::RUNS) . ' WHERE rule_id = %s', [$ruleId]);
    }

    public function pruneRuns(int $keep, int $cutoff, int $limit, bool $dryRun = false): int
    {
        $table = $this->t(Schema::RUNS);
        $done = 0;
        if ($dryRun) {
            $rows = $this->db->select('SELECT COUNT(*) AS c FROM ' . $table . " WHERE started < %d AND status <> 'running'", [$cutoff]);
            $done = (int) ($rows[0]['c'] ?? 0);
        } else {
            $done = $this->db->execute('DELETE FROM ' . $table . " WHERE started < %d AND status <> 'running' LIMIT %d", [$cutoff, $limit]);
        }
        $over = $this->db->select(
            'SELECT rule_id, COUNT(*) AS c FROM ' . $table . ' GROUP BY rule_id HAVING COUNT(*) > %d',
            [$keep]
        );
        foreach ($over as $row) {
            if ($done >= $limit) {
                break;
            }
            $ruleId = (string) $row['rule_id'];
            $edge = $this->db->select(
                'SELECT id FROM ' . $table . ' WHERE rule_id = %s ORDER BY id DESC LIMIT 1 OFFSET %d',
                [$ruleId, $keep - 1]
            );
            if ($edge === []) {
                continue;
            }
            $edgeId = (int) $edge[0]['id'];
            if ($dryRun) {
                $count = $this->db->select('SELECT COUNT(*) AS c FROM ' . $table . ' WHERE rule_id = %s AND id < %d AND started >= %d', [$ruleId, $edgeId, $cutoff]);
                $done += (int) ($count[0]['c'] ?? 0);
                continue;
            }
            $done += $this->db->execute(
                'DELETE FROM ' . $table . ' WHERE rule_id = %s AND id < %d LIMIT %d',
                [$ruleId, $edgeId, $limit - $done]
            );
        }
        return $dryRun ? $done : min($done, $limit);
    }

    public function insertTick(array $row): void
    {
        $columns = [];
        $parts = [];
        $params = [];
        foreach ($row as $column => $value) {
            $this->assertColumn(Schema::TICKS, $column);
            $columns[] = $column;
            $parts[] = is_int($value) ? '%d' : '%s';
            $params[] = $value;
        }
        $this->db->execute(
            'INSERT INTO ' . $this->t(Schema::TICKS) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $parts) . ')',
            $params
        );
    }

    public function latestTicks(): array
    {
        $table = $this->t(Schema::TICKS);
        $rows = $this->db->select(
            'SELECT t.* FROM ' . $table . ' t JOIN (SELECT lane, MAX(id) AS id FROM ' . $table . ' GROUP BY lane) m ON m.id = t.id'
        );
        $out = [];
        foreach ($rows as $row) {
            $tick = $this->tickRow($row);
            $out[$tick['lane']] = $tick;
        }
        ksort($out);
        return $out;
    }

    public function lastTickStart(string $lane, string $source): ?int
    {
        $rows = $this->db->select(
            'SELECT MAX(started) AS s FROM ' . $this->t(Schema::TICKS) . ' WHERE lane = %s AND source = %s',
            [$lane, $source]
        );
        $value = $rows[0]['s'] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }

    public function pruneTicks(int $cutoff, int $limit, bool $dryRun = false): int
    {
        if ($dryRun) {
            $rows = $this->db->select('SELECT COUNT(*) AS c FROM ' . $this->t(Schema::TICKS) . ' WHERE started < %d', [$cutoff]);
            return (int) ($rows[0]['c'] ?? 0);
        }
        return $this->db->execute('DELETE FROM ' . $this->t(Schema::TICKS) . ' WHERE started < %d LIMIT %d', [$cutoff, $limit]);
    }

    private function t(string $table): string
    {
        return $this->db->prefix() . $table;
    }

    private function assertColumn(string $table, string $column): void
    {
        if (!in_array($column, Schema::COLUMNS[$table], true)) {
            throw new StorageException(sprintf('Unknown column "%s".', $column));
        }
    }

    /**
     * @param array<string, int|string|null> $fields
     * @return array{0: string, 1: list<int|string>}
     */
    private function assignments(array $fields, string $table): array
    {
        $parts = [];
        $params = [];
        foreach ($fields as $column => $value) {
            $this->assertColumn($table, $column);
            if ($value === null) {
                $parts[] = $column . ' = NULL';
                continue;
            }
            $parts[] = $column . ' = ' . (is_int($value) ? '%d' : '%s');
            $params[] = $value;
        }
        return [implode(', ', $parts), $params];
    }

    /**
     * @param array<string, int|string|null> $where
     * @return array{0: string, 1: list<int|string>}
     */
    private function conditions(array $where, string $table): array
    {
        $parts = [];
        $params = [];
        foreach ($where as $column => $value) {
            $this->assertColumn($table, $column);
            if ($value === null) {
                $parts[] = $column . ' IS NULL';
                continue;
            }
            $parts[] = $column . ' = ' . (is_int($value) ? '%d' : '%s');
            $params[] = $value;
        }
        return [implode(' AND ', $parts), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, int|string|null>
     */
    private function typed(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if ($value === null) {
                $out[$key] = null;
            } elseif (in_array($key, self::INT_COLUMNS, true)) {
                $out[$key] = (int) $value;
            } else {
                $out[$key] = is_scalar($value) ? (string) $value : '';
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return StateRow
     */
    private function stateRow(array $row): array
    {
        /** @var StateRow */
        return $this->typed($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return RunRow
     */
    private function runRow(array $row): array
    {
        $typed = $this->typed($row);
        $typed['log'] = $typed['log'] ?? '';
        /** @var RunRow */
        return $typed;
    }

    /**
     * @param array<string, mixed> $row
     * @return LockRow
     */
    private function lockRow(array $row): array
    {
        /** @var LockRow */
        return $this->typed($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return TickRow
     */
    private function tickRow(array $row): array
    {
        /** @var TickRow */
        return $this->typed($row);
    }
}
