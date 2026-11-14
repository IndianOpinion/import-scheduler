# Architecture

This document describes how import-scheduler 0.1.0 is built and why. The
behaviour it implements is in [PRD.md](PRD.md); case numbers below (4.3.6 and
so on) refer to it.

## Modules

| File | Role |
|---|---|
| `import-scheduler.php` | Plugin header. Loads the autoloader when Composer has not, then boots `Plugin`. |
| `uninstall.php` | Drops tables and options on every site (`Installer::uninstall`). |
| `src/functions.php` | The four public functions. Each catches its own exceptions. |
| `src/autoload.php` | PSR-4 autoloader for installs without Composer. |
| `src/Plugin.php` | Wiring and WordPress hooks. One instance per request; tests build their own. |
| `src/Rule.php`, `src/Registry.php` | A registered rule (validated, immutable) and the rules of this request. |
| `src/Settings.php` | Defaults, ranges, validation, the kill switch constant, lane concurrency and lane pause. |
| `src/Tick.php` | One tick of one lane: guards, slot, sync, due rules within the budget, record, prune. |
| `src/Runner.php` | One run of one rule: rule lock, run row, call, result mapping, next run, release, shutdown handler. |
| `src/RunContext.php`, `src/RunResult.php` | What a runner receives and returns. |
| `src/Adapter.php`, `src/DescribesItself.php` | The importer interface, and an optional label. |
| `src/Sync.php` | Brings stored state in line with registration; pulls in parked next runs; marks abandoned runs. |
| `src/Locks.php` | Lock take, takeover, renew, release, unlock; lock names and expiry arithmetic. |
| `src/NextRun.php` | Next-run arithmetic: success, overrun, failure backoff, jitter. |
| `src/Guards.php`, `src/Memory.php` | Pre-flight guards (disk, memory, lane pause, filter) and the memory ceiling. |
| `src/Pruner.php` | Retention for runs and tick records, and forgetting orphans. |
| `src/Operator.php` | Operator actions shared by the screen and WP-CLI. None of them runs a rule. |
| `src/RuleState.php` | Read-only snapshot of a rule: stored state, registration and overrides combined. |
| `src/Installer.php` | Table creation, column check, upgrade, deactivation, uninstall. |
| `src/Fallback.php` | The optional WP-Cron fallback. |
| `src/Admin/Screen.php` | Tools, Import scheduler: lanes, rules, history, settings. |
| `src/Cli/Command.php`, `src/Cli/Io.php`, `src/Cli/WpCliIo.php` | `wp import-scheduler` and its output channel. |
| `src/Feed/FeedAdapter.php` | Reference adapter over core `fetch_feed()`. |
| `src/Storage/Storage.php` | Persistence contract. |
| `src/Storage/SqlStorage.php`, `src/Storage/Connection.php`, `src/Storage/WpdbConnection.php` | The MySQL implementation over a thin connection (`$wpdb` in WordPress). |
| `src/Storage/Schema.php` | Table definitions, `SHOW COLUMNS` verification, drop. |

## Data flow of a tick

```
system cron
  -> wp import-scheduler tick --lane=L
       Settings (fresh), storage clock read once  ........ DB down: exit 1
       wp-cron source and a CLI tick in the last hour?  .. exit 0 silently
       kill switch?  ...................................... "disabled", tick row, exit 0
       no rules registered?  .............................. "no rules", exit 0
       guards (lane pause, disk, memory, filter)  ......... "stood down: reason", tick row, exit 0
       take slot lane:L:1..N  ............................. none free: "lane busy", tick row, exit 0
       Sync: one read of all rule rows; insert new rows; fix effective lanes; flag orphans;
             pull in parked next runs; mark abandoned runs in lane L
       due = one indexed query, lane L, not paused, not orphaned, next_run <= now, LIMIT 200
       for each due rule, oldest first:
            budget spent? stop
            Runner: take rule:<id> (held: skip, next run untouched)
                    run row 'running', state last_start + running_run_id
                    call runner with RunContext, output buffered, Throwable caught
                    lock still ours?  yes: state + next run, release
                                      no:  run 'lock_lost', state untouched apart from a warning
                    import_scheduler_run_finished
            renew slot; re-read settings; kill switch, lane pause or memory ceiling? stop
       release slot (only if ours), tick row, daily prune claim, tick_finished action, summary line
```

Missing tables surface as `SchemaException` from the storage. The tick then
creates them once and starts again; a second failure exits 1 with a message.

## Data

Four InnoDB tables with the site prefix.

- `import_scheduler_rules`: one row per rule id. Columns as in PRD 6.1 plus
  `lane` (the effective lane, kept in step by Sync so the due query can use an
  index), `running_run_id` (the open run, if any) and `tick_orphan` (set by the
  tick when the rule is not registered in the WP-CLI process). Index
  `due (lane, paused, tick_orphan, next_run)`.
- `import_scheduler_runs`: one row per run. Indexes `(rule_id, started)` and
  `(started)`.
- `import_scheduler_locks`: name, owner, acquired, expires, heartbeat. Names
  `lane:<lane>:<slot>`, `rule:<id>` and `prune:daily`.
- `import_scheduler_ticks`: one row per lane per tick: lane, slot, source
  (`cli` or `wp-cron`), started, finished, outcome (`ran`, `nothing_due`,
  `busy`, `stood_down`, `disabled`), reason, runs, failures. Kept 7 days.

Options: `import_scheduler_settings` (not autoloaded),
`import_scheduler_schema_version` (autoloaded) and
`import_scheduler_missing_columns` (only while a column is missing).

## Rulings on the PRD's open questions

1. **Database double for tests.** The unit suite uses an in-memory double of
   the storage (`tests/Support/MemoryStorage.php` over a shared
   `MemoryDatabase`). It honours the primary keys and conditional updates the
   SQL storage relies on, compares lock times with its own clock, and counts
   statements. Two `MemoryStorage` objects over one `MemoryDatabase` model two
   processes with two connections; PHP runs one operation at a time, so each
   operation is atomic, as one SQL statement is on InnoDB. Real atomicity of
   the single-statement takeover is proved by one MySQL integration test
   (`tests/Integration/MysqlLockRaceTest.php`), skipped unless
   `IMPORT_SCHEDULER_TEST_DSN` is set: eight separate PHP processes, each on
   its own connection, race for one expired lock and exactly one may win.
   CI runs it against a MySQL service. Reason: no SQLite driver is assumed on
   developer machines, and a hand-written double keeps the suite fast and
   free of external services, while the one property that needs a real
   database gets a real database.
2. **Per-lane tick records.** A fourth table, one row per lane per tick, kept
   7 days and pruned by the daily pass. Reason: keeping them out of the locks
   table keeps every lock query simple, and a short history of ticks answers
   "is cron running, and what did it do" on the screen and in `status`.
3. **Multi-slot claims.** One slot per claim per lane in 0.1.0. Each tick
   takes one slot and relies on the rule lock to skip rules another slot is
   running (4.3.6). Reason: simplest correct form; no claim table or claim
   step to get wrong. Claiming several slots per tick is on the roadmap.
4. **Median duration.** Computed in PHP over the last 20 finished runs per
   rule, never in SQL. One query fetches the durations of the rules on the
   page, newest first; PHP keeps 20 per rule and takes the median. Reason:
   MySQL 5.7 has no window functions, and retention bounds the rows read
   (`history_keep`, default 50 per rule).
5. **URLs per rule.** A rule is one URL. An adapter that bundles many URLs
   registers each as its own rule. Reason: each source then gets its own
   interval, lock, history and failure count, which is the point of the
   plugin.
6. **Network tick.** Single site only in 0.1.0. On multisite each site runs
   its own lanes with `--url`; tables are per site. Reason: a network tick
   would share one budget across sites and blur their failures.
7. **Stopping a whole lane.** Yes: a per-lane pause switch in addition to the
   global kill switch. The `paused_lanes` setting, `wp import-scheduler
   pause-lane <lane>` and `resume-lane <lane>`, and Pause lane and Resume lane
   buttons on the screen. A paused lane's ticks stand down with the reason
   "lane paused"; a tick in progress stops choosing rules after its current
   run; running rules see `shouldStop()` turn true. Reason: an operator often
   needs to stop one class of source (a slow upstream, a maintenance window)
   without stopping the rest.

## Other decisions

- **Effective lane and orphan flag stored on the rule row.** The due query
  must be one indexed query capped at 200 rows (4.15.7). Registration is in
  code, so the row stores the effective lane (registered lane or override),
  refreshed by Sync. Orphans keep their old `next_run` forever, so without a
  flag 200 orphans could fill every due page and starve the lane; the tick
  sets `tick_orphan` and the due query and index exclude them. Only a tick
  writes the flag, because what the WP-CLI process sees is what matters; the
  screen lists registered rules with the flag set as "Registration
  problems" (4.8.9).
- **Open run on the rule row.** `running_run_id` lets Sync find abandoned
  runs (4.8.2) from the one read of all rule rows it already makes, instead of
  scanning the runs table. A run is abandoned when its rule lock is gone or
  expired; the update is conditional on the run still being
  `running`, so a run that finishes at the same moment is not overwritten.
- **Statement budget.** A tick with nothing due costs six statements: clock,
  slot take, rule rows, due query, slot release, and the tick row from ruling
  2. The PRD's budget of five predates that ruling. The daily prune is
  claimed through a lock row `prune:daily` that expires after a day, and only
  ticks that start within `expected_cadence + budget` seconds of the top of an
  hour try the claim, so other ticks spend nothing on it.
- **Lock take.** `INSERT IGNORE` then, if no row was inserted, one
  conditional `UPDATE ... WHERE name = ? AND expires < UNIX_TIMESTAMP()`.
  `INSERT IGNORE` reports a duplicate key as zero affected rows without a
  `$wpdb` error. Renew follows its `UPDATE` with a read of the row, because
  MySQL reports zero changed rows when a renew lands in the same second as
  the take.
- **Ownership check before the state write.** After a run, the runner reads
  its rule lock. If it still owns it, it writes the state (including next
  run) and only then releases the lock, so no other process can start the
  rule between the release and the new next run. If it does not own it, the
  run is `lock_lost` and the state's next run is left to the newer run.
- **Time.** The database clock is read once per tick and advanced by the
  monotonic clock (`DbTime`). Lock statements use `UNIX_TIMESTAMP()`
  directly. PHP's wall clock is used only to schedule WP-Cron fallback
  events. Durations use `hrtime`.
- **Adapter return type.** Declared in the docblock, not as a native union,
  so implementations do not have to repeat a long union return type.
- **Overrun with a failure result.** Recorded as `overrun` with the original
  `failure` kept; it counts towards consecutive failures (and auto-pause) but
  is scheduled as an overrun (finish plus a full interval), per 4.6.2.
- **Resume resets the failure counter.** Otherwise a rule auto-paused after N
  failures would pause again on its very next failure.
- **Stop requests.** Cleared when a run starts and when it ends, so a stale
  request never stops a later run. `stop` on a rule that is not running warns
  and changes nothing. `shouldStop()` checks the deadline on every call and
  polls the database (stop request, kill switch, lane pause) at most every 5
  seconds.
- **Missing tables.** Storage raises `SchemaException`. The tick and the
  screen create the tables once and retry; this is also how must-use installs
  get their tables (PRD section 10).
- **Column check.** Every install runs `SHOW COLUMNS` on each table and stores
  any missing column; the screen and `status` report it until a later install
  finds it present (4.13.2).
- **Deactivation message.** The screen carries a fixed note that deactivating
  keeps tables, settings and history and removes only fallback events, and
  that the cron line must be removed by hand (4.13.3).
- **Unlock from the screen.** Unlocking a held lock first redirects to a
  confirmation notice with the holder and expiry; only its second POST, with
  `force`, removes it (4.5.10).
- **Notices.** Screen actions redirect back with a per-user transient notice,
  so nothing user-controlled is reflected from the URL.
- **`--quiet`.** WP-CLI's global flag. Run lines go through `WP_CLI::log()`,
  which it silences; failures and the summary go through `WP_CLI::line()`,
  which it does not.
- **Feed adapter.** The cache lifetime filter is added at priority 999 around
  the `fetch_feed()` call only and removed in a `finally`. The timeout is set
  through core's `wp_feed_options` action on the feed object, for this feed
  URL only. Dropped (filtered) items count as skipped in the run message.
- **WP-Cron fallback.** One event per lane on a custom schedule equal to
  `expected_cadence`. Turning the setting off, deactivating or uninstalling
  removes all of them with `wp_unschedule_hook()`. The handler checks the
  ticks table for a `cli` tick in the last hour before doing anything.
- **Settings.** One array option. Values from the screen, from `pause-lane`
  and from a stored option edited by hand all pass the same validation; an
  invalid stored value falls back to its default.
- **Style and analysis.** PSR-12 via PHPCS (WP-CLI's `list_` method name is
  the one exception). PHPStan level 8 on `src` with the WordPress and WP-CLI
  stubs.

## Roadmap

- Claim several lane slots in one tick (ruling 3).
- A network-wide tick for multisite (ruling 6).
