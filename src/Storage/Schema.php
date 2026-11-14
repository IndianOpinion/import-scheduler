<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Storage;

/**
 * Table definitions, install, column verification and drop.
 *
 * The statements follow the format the WordPress table helper expects (two
 * spaces after PRIMARY KEY, one column per line). That helper can skip a
 * column add without an error, so every install is followed by a column
 * check with SHOW COLUMNS.
 */
final class Schema
{
    public const VERSION = '1';
    public const VERSION_OPTION = 'import_scheduler_schema_version';

    public const RULES = 'import_scheduler_rules';
    public const RUNS = 'import_scheduler_runs';
    public const LOCKS = 'import_scheduler_locks';
    public const TICKS = 'import_scheduler_ticks';

    public const COLUMNS = [
        self::RULES => [
            'rule_id', 'lane', 'lane_override', 'interval_override', 'paused', 'pause_reason', 'next_run',
            'last_start', 'last_finish', 'last_duration_ms', 'last_status', 'last_items', 'last_message',
            'consecutive_failures', 'consecutive_overruns', 'last_success_start', 'stop_requested',
            'running_run_id', 'tick_orphan', 'first_seen', 'updated',
        ],
        self::RUNS => [
            'id', 'rule_id', 'lane', 'started', 'finished', 'duration_ms', 'status', 'original_status',
            'items', 'message', 'log', 'owner',
        ],
        self::LOCKS => ['name', 'owner', 'acquired', 'expires', 'heartbeat'],
        self::TICKS => [
            'id', 'lane', 'slot', 'source', 'started', 'finished', 'outcome', 'reason', 'runs', 'failures',
        ],
    ];

    public function __construct(private Connection $db, private string $charsetCollate = '')
    {
    }

    public function table(string $name): string
    {
        return $this->db->prefix() . $name;
    }

    /**
     * @return array<string, string> CREATE TABLE statements keyed by short name.
     */
    public function statements(): array
    {
        $c = $this->charsetCollate !== '' ? ' ' . $this->charsetCollate : '';
        return [
            self::RULES => "CREATE TABLE {$this->table(self::RULES)} (
rule_id varchar(64) NOT NULL,
lane varchar(32) NOT NULL DEFAULT 'default',
lane_override varchar(32) NULL DEFAULT NULL,
interval_override int unsigned NULL DEFAULT NULL,
paused tinyint(1) NOT NULL DEFAULT 0,
pause_reason varchar(191) NOT NULL DEFAULT '',
next_run bigint NOT NULL DEFAULT 0,
last_start bigint NULL DEFAULT NULL,
last_finish bigint NULL DEFAULT NULL,
last_duration_ms bigint unsigned NULL DEFAULT NULL,
last_status varchar(20) NOT NULL DEFAULT '',
last_items int unsigned NULL DEFAULT NULL,
last_message varchar(500) NOT NULL DEFAULT '',
consecutive_failures int unsigned NOT NULL DEFAULT 0,
consecutive_overruns int unsigned NOT NULL DEFAULT 0,
last_success_start bigint NULL DEFAULT NULL,
stop_requested tinyint(1) NOT NULL DEFAULT 0,
running_run_id bigint unsigned NULL DEFAULT NULL,
tick_orphan tinyint(1) NOT NULL DEFAULT 0,
first_seen bigint NOT NULL DEFAULT 0,
updated bigint NOT NULL DEFAULT 0,
PRIMARY KEY  (rule_id),
KEY due (lane,paused,tick_orphan,next_run)
) ENGINE=InnoDB{$c};",
            self::RUNS => "CREATE TABLE {$this->table(self::RUNS)} (
id bigint unsigned NOT NULL AUTO_INCREMENT,
rule_id varchar(64) NOT NULL,
lane varchar(32) NOT NULL,
started bigint NOT NULL,
finished bigint NULL DEFAULT NULL,
duration_ms bigint unsigned NULL DEFAULT NULL,
status varchar(20) NOT NULL,
original_status varchar(20) NOT NULL DEFAULT '',
items int unsigned NULL DEFAULT NULL,
message varchar(500) NOT NULL DEFAULT '',
log text NULL,
owner char(32) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
KEY rule_started (rule_id,started),
KEY started (started)
) ENGINE=InnoDB{$c};",
            self::LOCKS => "CREATE TABLE {$this->table(self::LOCKS)} (
name varchar(191) NOT NULL,
owner char(32) NOT NULL,
acquired bigint NOT NULL,
expires bigint NOT NULL,
heartbeat bigint NOT NULL,
PRIMARY KEY  (name)
) ENGINE=InnoDB{$c};",
            self::TICKS => "CREATE TABLE {$this->table(self::TICKS)} (
id bigint unsigned NOT NULL AUTO_INCREMENT,
lane varchar(32) NOT NULL,
slot tinyint unsigned NOT NULL DEFAULT 0,
source varchar(10) NOT NULL DEFAULT 'cli',
started bigint NOT NULL,
finished bigint NOT NULL,
outcome varchar(20) NOT NULL,
reason varchar(191) NOT NULL DEFAULT '',
runs int unsigned NOT NULL DEFAULT 0,
failures int unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY lane_started (lane,started),
KEY started (started)
) ENGINE=InnoDB{$c};",
        ];
    }

    /**
     * Create or upgrade the tables. $apply receives the statements; in
     * WordPress it is the core table helper. Without one, each statement is
     * run as CREATE TABLE IF NOT EXISTS.
     *
     * @param (callable(list<string>): mixed)|null $apply
     * @return array<string, list<string>> Missing columns after the install.
     */
    public function install(?callable $apply = null): array
    {
        $statements = array_values($this->statements());
        if ($apply !== null) {
            $apply($statements);
        } else {
            foreach ($statements as $sql) {
                $this->db->execute((string) preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql));
            }
        }
        return $this->missingColumns();
    }

    /**
     * Verify every expected column with SHOW COLUMNS. A table that does not
     * exist lists all its columns as missing.
     *
     * @return array<string, list<string>>
     */
    public function missingColumns(): array
    {
        $missing = [];
        foreach (self::COLUMNS as $table => $expected) {
            try {
                $rows = $this->db->select('SHOW COLUMNS FROM ' . $this->table($table));
            } catch (\IndianOpinion\ImportScheduler\Exception\StorageException $e) {
                $rows = [];
            }
            $have = [];
            foreach ($rows as $row) {
                $field = $row['Field'] ?? $row['field'] ?? null;
                if (is_string($field)) {
                    $have[] = $field;
                }
            }
            $gone = array_values(array_diff($expected, $have));
            if ($gone !== []) {
                $missing[$table] = $gone;
            }
        }
        return $missing;
    }

    public function drop(): void
    {
        foreach (array_keys(self::COLUMNS) as $table) {
            $this->db->execute('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }
}
