# import-scheduler: product requirements

Status: draft for review. Audience: the engineer who builds version 0.1.0
from this document alone.

One line: runs each import rule on its own interval on a dedicated cron lane
with its own lock, so one slow pass never starves the rest, with per-rule
last run, next run and duration shown on one screen.

Written from scratch; not derived from any third-party plugin.

## 1. Problem

A news or aggregation site imports from many sources. The usual set-up runs
every import rule inside one scheduled hook. One pass walks every rule in
turn, and the pass is guarded by one lock so two passes never overlap.

That design fails quietly as the rule count grows:

- One pass over every rule takes longer than the cron cadence. Every tick
  that fires during a pass finds the lock held and does nothing. The rule
  intervals on the settings screen are nominal; the real cadence is set by
  the length of the whole pass.
- One slow or hung source holds the lock for everyone. A feed that takes ten
  minutes to time out delays every other feed by ten minutes.
- When the import shares the general WordPress cron run, it also delays
  scheduled posts, update checks and every other plugin's jobs.
- One fatal error in one rule (a type error in a parser, say) kills the whole
  pass, so every rule after it is skipped until the next pass.
- Nobody can see any of this. The lane writes its output to nowhere, and the
  operator sees only "imports feel slow".

Measured result from the production site this came out of: a large rule set
ran at about half its configured cadence before the lanes were split. A
single pass ran for an hour or more and every tick inside that window was
skipped. Moving the import to its own lane, with per-rule scheduling inside
it, restored the configured cadence.

This plugin is that fix as a reusable component. Each rule is a separate
unit of work with its own interval, its own lock, its own history, and a run
that cannot block the others for longer than a bounded time.

## 2. Goals and non-goals

### Goals

1. A registration API so any plugin or theme can register an import rule: a
   callable or an adapter object, with an id, an interval and a lane.
2. A WP-CLI command that system cron calls on a short cadence. Each call is
   a tick: it runs the rules that are due, oldest due first, within a
   wall-clock budget, then exits.
3. A lock held in the database as a row with an expiry, per lane and per
   rule. No lock files. A crashed process never wedges a lane for longer than
   the expiry.
4. Overrun handling: a run that takes longer than its limit is recorded,
   never stacks a second copy of itself, never triggers a burst of catch-up
   runs, and never stops other rules from running.
5. Per-rule failure isolation: an exception or error in one rule is recorded
   against that rule, and the tick moves on to the next rule.
6. One admin screen that shows, per rule: lane, interval, status, last run,
   last duration, last result, next run and failure count; and per lane: last
   tick, lock holder and lock expiry.
7. WP-CLI commands to list, inspect, run, pause, resume, re-time and unlock
   rules, and to print the system cron line for a lane.
8. A reference adapter for WordPress core's own feed fetching (`fetch_feed`)
   that emits each feed item through this plugin's own action, so any
   consumer can turn items into posts.

### Non-goals

- Creating posts. The reference adapter emits normalised items through an
  action. Turning an item into a post, a draft or anything else is the
  consumer's job. An example in `examples/` shows how.
- De-duplicating items across runs. The adapter supplies a stable key per
  item so a consumer can de-duplicate; it keeps no seen-item store.
- Installing a crontab entry. The plugin documents the line and can print
  it. It never writes to the system crontab, never shells out.
- Killing a running callable. PHP cannot interrupt a callable safely from
  inside the same process. Overruns are handled by recording, by
  cooperative stop signals and by lock expiry (see 4.7).
- Adapters for any third-party importer. Only the core `fetch_feed`
  adapter ships. Any other importer is wrapped by its own owner through the
  public API.
- A general job queue or a replacement for WP-Cron. It schedules registered
  import rules only.
- Running a rule more than once per tick, or in parallel with itself.

## 3. Users and setting

- **Who installs it.** A site operator or developer running a WordPress
  site that imports content on a schedule from many sources. They can edit
  the system crontab on the host (VPS, dedicated server, container with a
  cron sidecar, or a managed host that offers "server cron" with WP-CLI).
- **Who registers rules.** Plugin and theme developers, through the PHP API
  in section 5. Operators can change interval, lane and enabled state per
  rule from the screen or WP-CLI without touching code.
- **Where it runs.** The tick runs in a WP-CLI process started by system
  cron as the web server user. The admin screen runs in wp-admin. Nothing
  runs inside front-end page requests unless the operator turns on the
  WP-Cron fallback (case 4.12.3).
- **Hosts.** Single server or several web nodes sharing one database. The
  lock lives in the database, so it holds across nodes; clock reads for the
  lock come from the database (case 4.5.9).
- Works as a normal plugin or as a must-use plugin through a one-line
  loader file.

## 4. Behaviour

### 4.1 Definitions

- **Rule.** A unit of import work registered through this plugin: an id, a
  label, a runner (a callable or an object implementing the adapter
  interface), a default interval, a lane, a maximum runtime and optional
  arguments.
- **Lane.** A named group of rules that one system cron line drives. Each
  lane has its own lock and its own concurrency (number of slots, default
  1). Lane `default` always exists.
- **Tick.** One invocation of `wp import-scheduler tick --lane=<lane>`. It
  takes a lane slot, runs due rules within its budget, releases the slot and
  exits.
- **Due.** A rule is due when it is enabled, not paused, registered in the
  current request, assigned to the lane being ticked, and its next run time
  is at or before the tick's start time.
- **Run.** One execution of one rule's runner inside a tick or a manual
  `run` command. Each run has a row in the run history.
- **Lock.** A row in the plugin's locks table: name, owner token, acquired
  at, expires at, heartbeat at. Names are `lane:<lane>:<slot>` and
  `rule:<rule id>`.
- **Max runtime.** The time a run is expected to finish within. It sets the
  rule lock's expiry and the cooperative stop deadline. It does not kill
  anything.
- **Overrun.** A run that finished but took longer than its max runtime, or
  longer than its interval.
- **Abandoned run.** A run that started and never recorded a finish (the
  process died) and whose rule lock has since expired.
- All stored times are UTC Unix seconds. Durations are milliseconds.
  Displayed times are in the site's time zone, with the UTC value on hover.

### 4.2 Registration

1. Rules are registered on the action `import_scheduler_register`, which the
   plugin fires on `init` at priority 20. Registering outside that action,
   before it fires, is queued and applied when it fires; registering after
   it has fired applies at once.
2. A rule id matches `^[a-z0-9][a-z0-9_-]{0,63}$`. Anything else is rejected.
3. Registering an id that is already registered is rejected. The first
   registration wins. The rejection is reported through `_doing_it_wrong()`
   and listed on the screen under "Registration problems".
4. Interval: an integer number of seconds. Minimum 60. Maximum 30 days. A
   value outside the range is rejected, not clamped, so a typo never
   silently becomes a different schedule.
5. Lane: matches `^[a-z0-9][a-z0-9_-]{0,31}$`. Default `default`. A lane
   comes into existence when the first rule names it.
6. Max runtime: integer seconds, at least 10, at most 6 hours. Default 300,
   or the interval if the interval is shorter.
7. Runner: a callable, or an instance of the adapter interface (section 5).
   A string that is not callable at registration time is rejected.
8. The public function returns `true` on success and `false` on rejection.
   The underlying registry throws a typed `InvalidRuleException`; the public
   function catches it, so a bad registration in some plugin never breaks
   the site.
9. Registration is code. The database holds only state and operator
   overrides (interval, lane, enabled, paused) keyed by rule id. A rule's
   state row is created the first time a tick or the screen sees the rule.
10. A state row whose id is no longer registered is an orphan. Orphans are
    shown greyed on the screen, never run, never deleted automatically (their
    history stays readable), and removed only by `wp import-scheduler prune
    --orphans` or the screen's "Forget" action.
11. A newly seen rule is due at once, unless it was registered with
    `start_delay` (seconds), in which case its first next run is now plus that
    delay. This lets a site spread many new rules instead of running them all
    in the first tick.
12. Optional `jitter` (seconds, at most a quarter of the interval): each
    computed next run gets a random offset in `[0, jitter]`. Default 0.

### 4.3 The tick

1. The tick reads the database clock once at start (`now`) and computes its
   deadline as `now + budget`. Default budget 55 seconds; `--budget` overrides
   it for one call, between 5 and 3600.
2. Pre-flight guards run first (4.11). If any guard says stand down, the tick
   records the reason against the lane, prints one line, and exits 0 without
   taking a lock.
3. The tick tries to take a lane slot, slot 1 up to the lane's concurrency,
   first free slot wins. If every slot is held and unexpired, the tick prints
   "lane busy", records the attempt time against the lane, and exits 0. A busy
   lane is normal, not an error.
4. Before choosing rules, the tick sweeps for abandoned runs in its lane
   (4.8.2).
5. It selects due rules in its lane ordered by next run ascending, ties by
   rule id. The oldest due rule always goes first, so a rule that has waited
   longest is never pushed back by rules that became due later.
6. For each rule in order: if the deadline has passed, stop choosing. Try to
   take the rule lock. If the rule lock is held and unexpired (a manual run,
   or another slot), skip the rule for this tick and leave its next run
   unchanged. Otherwise run it (4.4).
7. A rule runs at most once per tick, even if its interval is shorter than
   the time the tick has been running.
8. After each run the tick re-checks its own memory use. Above 80 per cent
   of `memory_limit` (when the limit is not -1), it stops choosing rules.
9. The tick renews its lane slot's heartbeat after each run.
10. When it stops, it releases its lane slot (only if it still owns it),
    prints a summary line, and exits.
11. Rules whose turn did not come in this tick stay due. The next tick picks
    them up first, because they are now the oldest due.
12. Exit status: 0 when the tick ran, found nothing due, found the lane busy,
    or stood down on a guard. 1 when the database is unreachable or the
    plugin's tables are missing. With `--strict`, also 1 when any rule run in
    the tick failed. Cron mail is therefore quiet in normal running.
13. `--dry-run` prints which rules would run, in order, and why the others
    would not, and takes no locks.

### 4.4 A single run

1. The runner is called with a run context (section 5). The context carries
   the rule id, lane, run id, start time, deadline (start plus max runtime),
   the rule's arguments, and methods `heartbeat()`, `should_stop()` and
   `log()`.
2. Before the call, the plugin writes the run row with status `running`,
   sets the state row's last start, and registers a shutdown handler for this
   run (4.8.3).
3. Output written by the runner (echo, print) is captured with an output
   buffer. The first 500 bytes are kept in the run's message if the runner
   returned no message of its own; the rest is discarded.
4. Result mapping:
   - returns a `RunResult`: its status, item count and message are used.
   - returns an integer: success, that many items.
   - returns `null` or `true`: success, item count unknown.
   - returns `false`: failure, message "runner returned false".
   - returns a `WP_Error`: failure, message from the error.
   - throws any `Throwable`: failure, message is the class and message,
     trimmed to 500 bytes. The tick continues with the next rule.
   - anything else: success, with a warning in the message.
5. Duration is measured with a monotonic clock (`hrtime`), not wall time.
6. After the call, the run row is closed with status, items, message, finish
   time and duration. The state row's last finish, last duration, last
   status, last message, last item count and counters are updated.
7. Status values: `success`, `failure`, `overrun` (finished, any result,
   took longer than max runtime or interval; the original result is kept in
   a separate column), `abandoned`, `lock_lost` (4.7.5).
8. A successful or overrun-success run resets the consecutive failure
   counter. An overrun increments the consecutive overrun counter; a run
   within limits resets it.
9. The rule lock is released only if this run still owns it.
10. The action `import_scheduler_run_finished` fires with the run record.

### 4.5 Locks

1. A lock is a row: name (primary key), owner token (random 32 hex
   characters per process and run), acquired at, expires at, heartbeat at.
2. Take: insert the row. If the insert fails on the primary key, take over
   only with a single conditional update that matches the name and an expiry
   in the past. One statement, no read-then-write gap. Exactly one of two
   racing processes can win.
3. Release: delete where name and owner token match. Releasing a lock you do
   not own is a no-op that reports false.
4. Renew (heartbeat): update expires at and heartbeat at where name and owner
   match. A false result means the lock was lost (4.7.5).
5. Lane slot expiry: tick budget plus the largest max runtime among the
   lane's rules, plus a grace of 60 seconds. Renewed after every run.
6. Rule lock expiry: the rule's max runtime plus 60 seconds of grace.
   Extended by `heartbeat()` to now plus max runtime plus grace.
7. An expired lock is treated as free. Nothing else is needed to recover
   from a crashed process.
8. No lock files, no `flock`, no MySQL named locks (`GET_LOCK` is held per
   connection and silently drops on a reconnect, and is invisible to the
   screen).
9. All lock time comparisons use the database's own clock
   (`UNIX_TIMESTAMP()` in the statement), never PHP's `time()`, so web
   nodes with skewed clocks agree.
10. `unlock <name>` removes an expired lock. With `--force` it removes an
    unexpired one, after printing the holder and expiry, and the screen
    asks for confirmation before doing the same.

### 4.6 Next run calculation

1. On success within limits: next run is the run's scheduled time plus the
   interval, where the scheduled time is the larger of its previous next run
   and the time it last became due. If that result is already in the past
   (the rule was waiting a long time for its turn), next run is now plus the
   interval. Missed slots are never run as a burst: there is at most one
   catch-up run.
2. On overrun: next run is the finish time plus the interval. The rule rests
   one full interval after a long run instead of becoming due at once.
3. On failure: next run is the finish time plus a backoff of interval times
   2 to the power (consecutive failures minus 1), capped at the larger of the
   interval and 6 hours. The first failure therefore retries at the normal
   interval.
4. Jitter, when set, is added after the above.
5. An operator "run now" (screen or `due <id>`) sets next run to now. It does
   not bypass the lane, the lock or the pause.
6. An operator interval change takes effect from the next calculation; the
   current next run is pulled in to now plus the new interval when that is
   sooner, and left alone otherwise.
7. Paused rules keep their next run but are never chosen. Resuming a rule
   whose next run is in the past makes it due at once (one run, not a burst).
8. Auto-pause: when consecutive failures reach the `pause_after_failures`
   setting (default 0, meaning never), the rule is paused with reason "too
   many failures", and the action `import_scheduler_rule_paused` fires.

### 4.7 Overruns

1. A run longer than its max runtime is not interrupted. When it finishes it
   is recorded as `overrun`, with its real duration and original result.
2. A long run never runs twice at once: its rule lock is held, so any other
   tick or manual run skips the rule (4.3.6).
3. A long run never blocks other lanes. It holds only its own rule lock and
   one slot of its own lane. A lane with concurrency above 1 keeps running its
   other rules in the other slots.
4. Cooperative stop: `should_stop()` returns true once the run's deadline has
   passed, the tick has a stop request for the rule (`wp import-scheduler stop
   <id>`), or the plugin's kill switch is on. Runners that loop over items
   should check it between items, return what they have, and the run is
   recorded normally. The reference adapter does this.
5. Lock lost: if a run outlives its rule lock without heartbeats, another
   process may take the lock and start a second run. When the first run
   finishes and finds its lock gone, it records status `lock_lost` with its
   result, does not touch the state row's next run (the newer run owns it),
   and does not release the other process's lock. The screen shows the rule
   with a warning until the next clean run.
6. Repeated overruns: when consecutive overruns reach 3, the screen marks
   the rule "consistently slow" and suggests raising its max runtime or
   moving it to its own lane. The plugin never moves a rule by itself.
7. Rule with a runtime longer than its interval, on a one-slot lane: each
   tick that finds the lane busy exits at once. The rule runs back to back,
   resting one interval between runs (4.6.2), and the other rules on that lane
   wait. This is the documented reason to give slow rules a lane of their own.

### 4.8 Failure modes

1. Runner throws or returns an error: recorded as `failure` on that rule;
   the tick continues (fail open for the lane, closed for the rule).
2. Process killed mid-run (out of memory, SIGKILL, host restart): the run
   row stays `running`. When a later tick or the screen finds a `running` row
   whose rule lock is gone or expired, it marks the row `abandoned`, sets its
   finish time to the lock's expiry, increments consecutive failures, and
   computes next run as for a failure.
3. Fatal error inside the runner (memory exhausted, an uncatchable error):
   the shutdown handler from 4.4.2 reads `error_get_last()`, closes the run as
   `failure` with that message, and releases the rule lock and lane slot if
   it still owns them. If even that fails, 4.8.2 cleans up later.
4. Database unavailable at tick start: the tick prints the error and exits 1.
   It never runs a rule it cannot record.
5. Database write fails after a run: the error goes to the PHP error log with
   the rule id and result; the lock expiry handles cleanup.
6. Tables missing (plugin files deployed but not activated, or a failed
   upgrade): the tick tries to create them once (4.13); on failure it exits 1
   with a message saying so.
7. Runner never returns (a hung socket with no timeout): the process holds
   its locks until the operating system or a supervisor kills it; after that
   4.8.2 applies. The README says to give every HTTP call in a runner a
   timeout, and the reference adapter does.
8. Kill switch on (setting or constant, 4.12): the tick prints "disabled"
   and exits 0 without taking a lock. Runs in progress see `should_stop()`
   return true.
9. Rule registered in one request but not another (registration behind a
   conditional that is false under WP-CLI): the rule appears as an orphan in
   the tick. The screen's "Registration problems" box names rules seen in
   wp-admin but missing from the last tick, and the README says to register
   unconditionally.
10. Clock jumps (NTP step, daylight saving changes do not apply because
    everything is UTC): next run values in the future by more than twice the
    interval are pulled in to now plus the interval at tick start, so a
    forward clock error cannot park a rule for days.

### 4.9 The admin screen

1. Location: Tools, "Import scheduler". Capability `manage_options`.
   Filterable through `import_scheduler_capability`.
2. Lanes panel, one row per lane: concurrency, number of rules, last tick
   start, last tick outcome (ran N, nothing due, busy, stood down with
   reason), slot locks with holder token prefix and expiry. A lane whose last
   tick is older than three times the expected cadence (setting, default 60
   seconds) shows "No tick seen since <time>. Check the system cron line."
   with the exact line to add.
3. Rules table, one row per rule: label and id, lane, interval (with
   "overridden" marker), status (idle, due, running, paused, failing,
   consistently slow, orphan), last run start (relative and absolute), last
   duration, last result (status, item count, message), next run (relative
   and absolute; "now" when due), consecutive failures, median duration of
   the last 20 runs.
4. Sorting by any column, filtering by lane and status, search by id or
   label. Pagination at 100 rows.
5. Row actions: Run now (sets due), Pause, Resume, Change interval, Move to
   lane, Reset interval to default, View history, Forget (orphans only).
   Every action is a POST with a nonce and a capability check, and redirects
   back with a notice.
6. History view for one rule: the last N runs (setting, default 50) with
   start, duration, status, items, message.
7. The screen never runs a rule inside the web request. "Run now" only marks
   the rule due.
8. Times show in the site time zone; a "UTC" toggle shows raw UTC.
9. A "Registration problems" box lists rejected registrations from this
   request and rules seen in wp-admin but absent from the last tick (4.8.9).
10. Screen options let the operator hide columns.

### 4.10 WP-CLI

Every command lives under `wp import-scheduler`. Each prints a table by
default and supports `--format=table|json|csv|yaml|ids` where it lists.

1. `tick [--lane=<lane>] [--budget=<seconds>] [--dry-run] [--strict]`:
   section 4.3.
2. `list [--lane=<lane>] [--status=<status>] [--format=<f>]`: the rules
   table from 4.9.3.
3. `status`: lanes, locks with holders and expiry, last tick per lane,
   guard results, kill switch state.
4. `run <id> [--ignore-pause]`: runs one rule now in this process. Takes the
   rule lock but no lane slot. Refuses if the lock is held (prints holder and
   expiry). Prints the result. Exit 1 on failure.
5. `due <id>...`: sets next run to now.
6. `pause <id>... [--reason=<text>]` and `resume <id>...`.
7. `interval <id> <seconds>` and `interval <id> --reset`.
8. `lane <id> <lane>`: moves a rule to another lane (operator override).
9. `stop <id>`: asks a running rule to stop at its next `should_stop()`.
10. `history <id> [--limit=<n>]`.
11. `unlock <name> [--force]`: 4.5.10.
12. `prune [--orphans] [--dry-run]`: removes history beyond retention and,
    with `--orphans`, state and history for orphans.
13. `cron-line [--lane=<lane>] [--user=<user>] [--path=<path>]`: prints a
    suggested crontab line with placeholders filled from the arguments
    (no user or path is assumed;
    missing values print as `<user>` and `<path>`). It never writes a file.
14. Unknown rule ids exit 1 with "no such rule". Commands accept several ids
    where it makes sense.

### 4.11 Pre-flight guards

1. Free disk: when the `min_free_disk_mb` setting is above 0, the tick checks
   `disk_free_space()` on the uploads directory and stands down below it.
   Default 0 (off). If the function is disabled or fails, the guard passes
   (fail open) and the reason is noted in `status`.
2. Available memory: when `min_available_memory_mb` is above 0 and
   `/proc/meminfo` is readable, the tick stands down below that much
   `MemAvailable`. Default 0 (off). Unreadable file: guard passes, noted.
3. Custom guards: the filter `import_scheduler_should_tick` receives `true`
   and the lane name. Returning `false` or a string stands down; a string is
   used as the reason.
4. Every stand-down is recorded on the lane with its reason and time, and
   printed once. Guards self-heal: the next tick checks again.

### 4.12 Kill switch, settings and fallback

1. Setting `enabled` (default true). Off: 4.8.8.
2. Constant `IMPORT_SCHEDULER_DISABLE` set true in `wp-config.php` overrides
   the setting, for when wp-admin is unreachable.
3. WP-Cron fallback (default off): when on, the plugin schedules one WP-Cron
   event per lane at the expected cadence that runs the same tick code. The
   screen warns that this runs inside page requests and that a system cron
   line is better. When a system cron tick has been seen in the last hour,
   the fallback event exits at once, so turning it on by mistake does not
   double the work.

### 4.13 Install, upgrade, uninstall

1. Activation creates the four tables and the settings option. Upgrades
   compare a stored schema version and run the table creation again.
2. After any schema change the plugin verifies each expected column with
   `SHOW COLUMNS` and reports a missing one on the screen and in `status`,
   because the WordPress table helper can skip a column add without error.
3. Deactivation removes any WP-Cron fallback events. It leaves tables,
   settings and the crontab alone, and the screen's last message says so.
4. Uninstall drops the four tables and deletes the settings and schema
   version options. On multisite it does so for every site. It cannot remove
   the crontab line; the README says to.
5. Multisite: tables are per site. The tick runs for the site given by WP-CLI's
   `--url`. One cron line per site that has rules. Network-wide ticking is
   out of scope for 0.1.0.

### 4.14 Reference adapter: core feeds

1. `import_scheduler_register_feed( $id, $feed_url, $args )` registers a rule
   whose runner is the built-in feed adapter. Arguments: everything a rule
   takes, plus `source_name` (default: the feed title, then the feed host),
   `max_items` (default 50, at most 500), `timeout` (seconds, default 15),
   `since_last_success` (default false).
2. The feed URL must be an absolute `http` or `https` URL that passes
   `wp_http_validate_url()`. Otherwise registration is rejected.
3. The adapter calls `fetch_feed()`. Around that call only, it filters the
   feed cache lifetime down to half the rule's interval (minimum 60 seconds),
   so a run never reads a cached copy older than the schedule implies, and
   removes the filter afterwards.
4. `fetch_feed()` returning a `WP_Error` is a run failure with its message.
5. For each item, up to `max_items`, in feed order:
   - URL: the item permalink. Items without an absolute `http` or `https`
     URL are skipped and counted as skipped.
   - Title: HTML entities decoded, tags stripped, whitespace collapsed.
     Empty title: the item is skipped.
   - Body: the item content, else the description, else an empty string.
     Passed through `wp_kses_post()`.
   - Source name: from the rule arguments.
   - Published time: the item date as UTC Unix seconds, or null if missing or
     unparseable.
   - Key: the item id (guid) if present, else the URL. Stable across runs
     for the same item, so consumers can de-duplicate.
   - Feed URL and rule id.
6. With `since_last_success`, items with a published time at or before the
   start of the rule's last successful run are skipped. Items with no
   published time are always emitted.
7. Each item passes through the filter `import_scheduler_feed_item` (return
   `false` to drop it) and is then emitted through the action
   `import_scheduler_item` with the item array, the rule id and the run
   context. The action is the same one any adapter uses.
8. Between items the adapter checks `should_stop()` and returns early with
   what it has when it is true.
9. The run result: success, item count = items emitted, message "emitted N,
   skipped M".
10. An exception thrown by a consumer of `import_scheduler_item` fails that
    run (the plugin cannot know whether the consumer stored the item). The
    items emitted before it stay emitted.

### 4.15 Edge cases

1. Zero rules registered: the tick takes no lock, prints "no rules", exits 0.
2. Rule interval shorter than the cron cadence: the rule runs at most once
   per tick. The screen shows "effective cadence limited by cron" when the
   interval is shorter than the expected cadence.
3. Two ticks for the same lane start in the same second: exactly one takes a
   slot; the other prints "lane busy".
4. A manual `run` while the lane's tick is running the same rule: refused,
   lock held.
5. A rule moved to another lane while running: the run finishes under its old
   lane; the next run happens on the new lane.
6. A lane with no cron line: its rules are never run. The screen says so
   (4.9.2).
7. Thousands of rules: the tick selects due rules with one indexed query
   limited to 200 rows; the rest wait their turn.
8. Rule id reused after an orphan was forgotten: starts fresh, due at once.
9. Runner that registers or deregisters rules during a run: changes apply in
   the next request.
10. Runner calls `exit`: the shutdown handler records it as `failure`,
    "runner exited".

## 5. Public interface

Namespace `IndianOpinion\ImportScheduler`. Function prefix
`import_scheduler_`. Hook prefix `import_scheduler_`.

### 5.1 Functions

- `import_scheduler_register( string $id, callable|Adapter $runner, array
  $args = [] ): bool`. Args: `label` (string, default the id), `interval`
  (int seconds, required), `lane` (string, `default`), `max_runtime` (int
  seconds), `start_delay` (int, 0), `jitter` (int, 0), `args` (array passed
  to the runner through the context, default empty).
- `import_scheduler_register_feed( string $id, string $feed_url, array $args
  = [] ): bool`. Section 4.14.
- `import_scheduler_get_rule( string $id ): ?RuleState`. Read-only snapshot.
- `import_scheduler_mark_due( string $id ): bool`.

### 5.2 Classes and interfaces

- `interface Adapter { public function run( RunContext $context ): RunResult|int|bool|null|\WP_Error; }`
  Adapters may also implement `label(): string` through an optional
  interface `DescribesItself`.
- `final class RunContext`: `ruleId(): string`, `lane(): string`, `runId():
  int`, `startedAt(): int`, `deadline(): int`, `args(): array`,
  `heartbeat(): bool` (false when the lock is lost), `shouldStop(): bool`,
  `log( string $level, string $message ): void` (kept in the run row,
  last 2 KB).
- `final class RunResult`: named constructors `success( int $items = 0,
  string $message = '' )` and `failure( string $message )`.
- `final class RuleState`: read-only properties for every column in 6.1.
- Exceptions: `InvalidRuleException`, `LockException`,
  `SchemaException`, all extending `ImportSchedulerException`.

### 5.3 Actions

- `import_scheduler_register` (no arguments): register rules here.
- `import_scheduler_item( array $item, string $rule_id, RunContext $context )`.
- `import_scheduler_run_started( array $run )`.
- `import_scheduler_run_finished( array $run )`.
- `import_scheduler_rule_paused( string $rule_id, string $reason )`.
- `import_scheduler_tick_finished( array $summary )`: lane, started, ended,
  runs, outcome.

### 5.4 Filters

- `import_scheduler_should_tick( bool|string $ok, string $lane )`.
- `import_scheduler_feed_item( array|false $item, string $rule_id )`.
- `import_scheduler_capability( string $cap )`, default `manage_options`.
- `import_scheduler_settings( array $settings )`: final say over settings.
- `import_scheduler_lane_concurrency( int $slots, string $lane )`.

### 5.5 Constants

- `IMPORT_SCHEDULER_DISABLE` (bool): kill switch.

### 5.6 System cron line (documented, never installed)

One line per lane, run as the web server user, from a directory that user can
read, on a one-minute cadence, with each lane on its own minute offset if the
cadence is longer:

```
* * * * * <user> cd <path> && wp import-scheduler tick --lane=default --quiet >> <log file> 2>&1
```

Rules for the line, repeated in the README:

- Run as the user that owns the site files, never as root.
- Send output to a log file, not to `/dev/null`; the tick prints one line
  per run.
- No shell lock wrapper is needed. If a site adds one anyway it must be
  non-blocking (`flock -n`); a blocking wait would queue ticks behind a long
  run and starve the database lock's own fairness.
- Do not start several lanes, or this lane and other heavy jobs, on the same
  minute; offset them.
- Set `DISABLE_WP_CRON` only if another cron line still runs WP-Cron.

## 6. Data

### 6.1 What is stored

Four tables with the site's table prefix, all InnoDB, `utf8mb4`.

- `<prefix>import_scheduler_rules`: one row per rule id. Columns: rule id
  (primary key), lane override, interval override, paused (bool), pause
  reason, next run, last start, last finish, last duration ms, last status,
  last items, last message (500), consecutive failures, consecutive
  overruns, last success start, stop requested (bool), first seen, updated.
  Index on (lane, paused, next run).
- `<prefix>import_scheduler_runs`: one row per run. Columns: id
  (auto-increment), rule id, lane, started, finished, duration ms, status,
  original status (for overruns), items, message (500), log (2 KB), owner
  token. Index on (rule id, started) and on (started).
- `<prefix>import_scheduler_locks`: name (primary key), owner token, acquired,
  expires, heartbeat.
- `<prefix>import_scheduler_ticks`: one row per lane per tick (lane, slot,
  source, started, finished, outcome, reason, runs, failures), kept 7 days.
  The builder's ruling on open question 2 moved these records out of the
  locks table (see `docs/ARCHITECTURE.md`).

Options: `import_scheduler_settings` (array, not autoloaded),
`import_scheduler_schema_version` (autoloaded).

No item content is stored. Run messages are runner output and may contain
whatever the runner writes.

### 6.2 Retention

Per rule, the last `history_keep` runs (default 50), and nothing older than
`history_days` (default 30). Pruning runs at the end of at most one tick per
day, deleting at most 5,000 rows per pass.

### 6.3 Uninstall

Section 4.13.4.

## 7. Configuration and sensible defaults

| Setting | Default | Range | Meaning |
|---|---|---|---|
| `enabled` | true | bool | Kill switch (the constant wins) |
| `tick_budget` | 55 | 5 to 3600 s | Stop choosing new rules after this |
| `expected_cadence` | 60 | 60 to 3600 s | How often cron calls a tick; drives warnings |
| `default_max_runtime` | 300 | 10 s to 6 h | For rules that do not set one |
| `lock_grace` | 60 | 10 to 600 s | Added to every lock expiry |
| `lane_concurrency` | `{"default":1}` | 1 to 8 per lane | Slots per lane |
| `pause_after_failures` | 0 | 0 to 100 | 0 never pauses |
| `backoff_cap` | 21600 | 60 s to 7 days | Ceiling for failure backoff |
| `memory_ceiling_pct` | 80 | 50 to 95 | Stop choosing rules above this |
| `min_free_disk_mb` | 0 | 0 up | Guard, off by default |
| `min_available_memory_mb` | 0 | 0 up | Guard, off by default |
| `history_keep` | 50 | 5 to 1000 | Runs kept per rule |
| `history_days` | 30 | 1 to 365 | Runs older than this are pruned |
| `wp_cron_fallback` | false | bool | 4.12.3 |

Settings live on a small settings tab of the same screen and through
`wp option` for automation. Invalid values are rejected with a notice and the
previous value kept.

## 8. Security and privacy

- Every screen action needs the capability from 5.4 and a nonce. Every input
  is validated against the ranges in section 7 and the id patterns in 4.2.
- Every output on the screen is escaped. Run messages and logs are treated as
  untrusted text (they can contain feed content) and escaped as plain text.
- No `eval`, no shell, no `exec`, no `@` error suppression. The plugin never
  writes files.
- Owner tokens shown on the screen are truncated to 8 characters.
- The reference adapter fetches only the URLs a developer registered. It
  relies on core's `wp_safe_remote_*` behaviour through `fetch_feed()`, so
  private and loopback addresses are refused unless a site filter allows
  them.
- No personal data is stored by the plugin. A runner can put anything in its
  message; the README says not to log personal data there.
- No HTTP endpoint exists. There is no REST route and no AJAX action that
  runs a rule.

## 9. Performance budget

- A tick with nothing due: at most 6 database queries (clock, slot take,
  rule rows, due query, slot release, tick record) and under 50 ms of
  plugin time on a typical host, excluding WordPress bootstrap.
- Per run overhead (lock, run row, state update, release): at most 8 queries.
- Due-rule selection: one indexed query, capped at 200 rows.
- Admin screen with 500 rules: under 1 second server time, one query for
  rules, one aggregate query for medians over at most 20 runs per rule.
- Nothing runs on front-end requests unless the WP-Cron fallback is on.
- Memory: the plugin's own footprint under 2 MB in a tick.

## 10. Compatibility

- PHP 8.1, 8.2 and 8.3. No 8.2+ syntax.
- WordPress 6.4 and later. WP-CLI 2.8 and later.
- MySQL 5.7+ and MariaDB 10.4+ (needs `INSERT` on a primary key collision
  to fail cleanly, and `UNIX_TIMESTAMP()`).
- Works with `DISABLE_WP_CRON` true or false.
- Works on multi-node hosts sharing one database (locks are in the database).
- Object caches: lock and state reads always go to the database, never to
  the object cache or transients, because a cached lock is a wrong lock.
- Page caches are irrelevant: nothing is front-end.
- Must-use plugin: a one-line loader in `mu-plugins` that requires the main
  file. Activation hooks do not fire there, so the first tick or screen load
  creates the tables (4.8.6).

## 11. Observability

- The tick prints one line per run: time, lane, rule id, status, duration,
  items, first 120 characters of the message. And one summary line per tick.
  With `--quiet` only failures and the summary.
- Run history in the database (section 6) is the durable log.
- The screen (4.9) and `status` (4.10.3) show lane health, locks and guards.
- Failures, abandoned runs, lock-lost runs and auto-pauses also go to the PHP
  error log with the prefix `[import-scheduler]`, because cron output is
  often discarded.
- Actions in 5.3 let a site forward events to its own monitoring.
- A lane with no tick within three expected cadences is flagged on the
  screen and in `status`.

## 12. Acceptance tests

Each test names the cases it covers. Tests run under PHPUnit with a stub
layer for WordPress and a real or in-memory database double that honours a
primary key and conditional updates. Lock race tests use two connections.

1. T1 (4.2.1): a rule registered before, during and after the register
   action is present in the registry.
2. T2 (4.2.2, 4.2.5): ids and lanes with upper case, spaces, leading dash or
   over length are rejected; valid ones accepted.
3. T3 (4.2.3): a second registration of the same id returns false, the first
   runner stays, the problem is listed.
4. T4 (4.2.4, 4.2.6): intervals 59 and over 30 days, max runtimes 9 and over
   6 hours, are rejected; defaults apply when omitted.
5. T5 (4.2.7): a non-callable string runner is rejected; an adapter object is
   accepted.
6. T6 (4.2.8): an invalid registration does not throw out of the public
   function.
7. T7 (4.2.9, 4.2.10, 4.15.8): a state row exists after the first tick; an
   unregistered id becomes an orphan, is never run and is not deleted.
8. T8 (4.2.11, 4.2.12, 4.6.4): a new rule is due at once; with start delay it is due
   after the delay; with jitter next run lies within the jitter window.
9. T9 (4.3.1, 4.3.6, 4.3.9, 4.3.10): with a 5 second budget and three 3 second rules, the
   tick runs two and leaves the third due.
10. T10 (4.3.2, 4.11.1 to 4.11.4): each guard stands down, records a reason,
    takes no lock and exits 0; an unreadable meminfo passes.
11. T11 (4.3.3, 4.15.3): two concurrent ticks on a one-slot lane: one runs,
    the other prints "lane busy" and exits 0.
12. T12 (4.3.5, 4.3.11): due rules run oldest first; a rule left over from
    one tick runs first in the next.
13. T13 (4.3.7, 4.15.2): a rule with a 60 second interval in a long tick runs
    once.
14. T14 (4.3.8): memory above the ceiling stops selection.
15. T15 (4.3.12): exit codes for ran, nothing due, busy, stood down, database
    down, and failure with and without `--strict`.
16. T16 (4.3.13): dry run lists the order and takes no lock.
17. T17 (4.4.3, 4.4.4, 4.4.1): each return type and a thrown exception map to the
    right status, items and message; echoed output is captured and trimmed.
18. T18 (4.4.5 to 4.4.8, 4.4.2): durations, state columns and counters update; an
    overrun keeps its original result.
19. T19 (4.4.10, 5.3): run started and finished actions fire with the run
    record.
20. T20 (4.5.2, 4.5.1): of two processes taking an expired lock at once, exactly one
    wins.
21. T21 (4.5.3, 4.5.4, 4.4.9): release and renew by a non-owner change nothing and
    return false.
22. T22 (4.5.5, 4.5.6, 4.5.7): expiry values are computed as stated; an
    expired lock is taken over.
23. T23 (4.5.9): lock comparisons use the database clock when PHP's clock is
    skewed by an hour.
24. T24 (4.5.10): unlock refuses an unexpired lock without `--force`.
25. T25 (4.6.1): a rule that waited three intervals runs once and is next due
    one interval later, not three times.
26. T26 (4.6.2, 4.7.1): an overrun records `overrun` and next run is finish
    plus interval.
27. T27 (4.6.3): failure backoff doubles from the second failure and stops at
    the cap; success resets it.
28. T28 (4.6.5, 4.6.6, 4.6.7): run now, interval change and resume set next
    run as stated and respect pause.
29. T29 (4.6.8): auto-pause after N failures fires the action; 0 never pauses.
30. T30 (4.7.2, 4.15.4): a rule whose lock is held is skipped by a tick and
    refused by `run`.
31. T31 (4.7.3): on a two-slot lane, a long run in slot 1 does not stop slot 2
    running other rules.
32. T32 (4.7.4, 4.8.8): `should_stop()` turns true at the deadline, on a stop
    request and on the kill switch.
33. T33 (4.7.5): a run whose lock was taken over records `lock_lost`, leaves
    next run alone and does not release the new holder's lock.
34. T34 (4.7.6): three overruns mark the rule consistently slow.
35. T35 (4.7.7): a rule slower than its interval on a one-slot lane runs back
    to back with one interval of rest, and busy ticks exit at once.
36. T36 (4.8.1): a throwing rule fails and the next rule still runs.
37. T37 (4.8.2, 4.3.4): a `running` row with an expired lock becomes `abandoned` and
    counts as a failure.
38. T38 (4.8.3, 4.15.10): the shutdown handler closes a run after a fatal and
    after `exit`.
39. T39 (4.8.4, 4.8.6, 4.8.5): database down exits 1 without running; missing tables
    are created once or reported.
40. T40 (4.8.9): a rule registered only in wp-admin is listed as a
    registration problem.
41. T41 (4.8.10): a next run far in the future is pulled in.
42. T42 (4.9.1, 4.9.5, section 8): every screen action rejects a missing
    nonce and a user without the capability.
43. T43 (4.9.2, 4.15.6): a lane with no recent tick shows the warning and the cron
    line.
44. T44 (4.9.3, 4.9.6, 4.9.8, 4.9.4, 4.9.9, 4.9.10): the rules table and history show the stated
    columns, with times in the site zone and UTC on toggle.
45. T45 (4.9.7): "Run now" on the screen runs nothing in the request.
46. T46 (4.10.1 to 4.10.14): each CLI command, including unknown ids and
    output formats.
47. T47 (4.10.13): `cron-line` prints placeholders when values are missing and
    writes no file.
48. T48 (4.12.2, 4.12.1): the constant overrides an enabled setting.
49. T49 (4.12.3): the fallback event exits at once when a system tick was seen
    in the last hour.
50. T50 (4.13.2, 4.13.1): a missing column is detected and reported.
51. T51 (4.13.3, 4.13.4): deactivation removes fallback events only;
    uninstall drops tables and options, per site on multisite.
52. T52 (4.14.2, 4.14.1): non-http feed URLs are rejected.
53. T53 (4.14.3): the feed cache lifetime filter is set during the call and
    removed after.
54. T54 (4.14.4): a feed error fails the run with its message.
55. T55 (4.14.5): item normalisation: URL, title, body, source name, time,
    key; items without URL or title are skipped and counted.
56. T56 (4.14.6): `since_last_success` drops older items and keeps undated
    ones.
57. T57 (4.14.7): the filter can drop an item; the action receives the rest.
58. T58 (4.14.8): the adapter stops early when `should_stop()` turns true.
59. T59 (4.14.9, 4.14.10): the result counts emitted and skipped; a consumer
    exception fails the run.
60. T60 (4.15.1): zero rules: no lock, exit 0.
61. T61 (4.15.5, 4.15.9): a lane move during a run takes effect on the next run.
62. T62 (4.15.7, section 9): with 1,000 due rules the selection query returns
    at most 200 and uses the index.
63. T63 (6.2): pruning keeps the newest N runs per rule, drops runs past the
    age limit, at most once a day.
64. T64 (section 8): run messages containing markup are escaped on the
    screen.
65. T65 (4.5.8): a source scan finds no lock files, no `flock` and no MySQL
    named lock calls in the plugin.
66. T66 (4.8.7): the README tells authors to give every HTTP call in a runner
    a timeout, and the reference adapter sets one on its fetch.
67. T67 (4.13.5): on a two-site network, a tick with `--url` for one site
    reads and writes only that site's tables.

## 13. Open questions

For the builder to answer in `docs/ARCHITECTURE.md`.

1. Database double for tests: SQLite through PDO, or a hand-written fake that
   models primary keys and conditional updates. The lock race test (T20)
   needs real atomicity; say how it is achieved.
2. Whether the `tick:<lane>` records belong in the locks table or a fourth
   small table. The PRD puts them in the locks table; change it if it
   complicates the lock queries.
3. Whether lane concurrency above 1 should share one due-rule query with a
   claim step, or simply rely on the rule lock skip (4.3.6). The PRD assumes
   the simple form.
4. How the screen computes median duration cheaply on MySQL 5.7, which has no
   window functions. A stored rolling median in the rules row is acceptable.
5. Whether `import_scheduler_register_feed` should accept several URLs as one
   rule. The PRD says one URL per rule.
6. Whether to offer a network-wide tick for multisite in a later version.
7. Whether a stop request should also be honoured between rules in a tick
   (stop the lane), not only within a rule.

## 14. Lessons from production, written as rules

1. Give the import its own lane. Never run it inside the general WP-Cron
   run, where one long pass delays every other scheduled job on the site.
2. Schedule per rule, not per pass. A pass over every rule under one lock
   makes every rule's real interval equal to the pass length, and every tick
   inside the pass is wasted.
3. Order due work by how long it has waited. Otherwise the rules at the end
   of the list are the ones that never run.
4. Bound the tick, not the rule. Stop choosing new work at the budget; let
   the work in hand finish.
5. Isolate every rule in its own error handler. One type error in one parser
   must never skip every rule after it.
6. A lock needs an expiry and an owner. A lock without an expiry wedges the
   lane forever when a process is killed; a release without an owner check
   frees someone else's lock.
7. Keep the lock in the database, with the database's clock. Lock files do
   not cross web nodes, and per-connection database locks vanish on a
   reconnect and cannot be shown on a screen.
8. Never stack a blocking shell lock on top of an inner lock. The outer lock
   queues ticks and starves the inner one. If a shell lock is used at all, it
   is non-blocking.
9. Never fire every lane on the same minute. Stacked processes on one minute
   were the trigger for repeated database out-of-memory kills on the
   production host. Offset the lanes.
10. Stand down on low disk or low memory, say so in the log, and recover by
    itself on the next tick. A full disk took the database down once; an
    import that keeps writing makes it worse.
11. Do not send cron output to nowhere. A lane writing to `/dev/null` hid
    every failure for weeks. Keep a durable run history and print one line
    per run.
12. Never replay missed slots. After an outage, a rule runs once, not once
    per missed interval.
13. After a long run, rest a full interval. Scheduling from the start time of
    an overrun makes the rule due again the moment it ends and starves its
    lane.
14. Use an explicit "due now" action, not a magic past date written into a
    timestamp field, to make a rule run on the next tick.
15. If a callable you register also has its own WP-Cron event, unschedule
    that event (or exclude it from the general cron run). Otherwise it runs
    in both places.
16. Register rules unconditionally. A rule registered only in wp-admin does
    not exist in the WP-CLI process that runs the lane.
17. Give every network call in a runner a timeout. A run with no timeout can
    hold its lock until a person notices.
18. Check the cache in front of the fetch. A feed cache that lives longer than
    the rule's interval makes a frequent rule fetch stale content.
19. Run the lane as the user that owns the site files, from a directory that
    user can read, or WP-CLI fails before the plugin loads.
20. After any schema change, check the columns exist. The WordPress table
    helper can skip a column add and report nothing.
