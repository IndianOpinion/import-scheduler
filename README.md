# import-scheduler

Runs each import rule on its own interval on a dedicated cron lane, with its own database lock, so one slow pass never starves the rest. Per-rule last run, next run and duration show on one screen, and in WP-CLI.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

**Measured result.** On the production site this came from, a large rule set run as one pass under one lock ran at about half its configured cadence. Moving the import to its own lane, with per-rule scheduling inside it, restored the configured cadence.

## Why

The usual set-up runs every import rule inside one scheduled hook, under one lock. As the rule count grows, one pass takes longer than the cron cadence and every tick inside it does nothing; one hung source holds the lock for everyone; one fatal error skips every rule after it; and nobody can see any of it. This plugin makes each rule its own unit of work: its own interval, its own lock, its own history, and a tick that stops choosing new work when its time budget is spent.

## Install

Requirements: PHP 8.1+, WordPress 6.4+, WP-CLI 2.8+, MySQL 5.7+ or MariaDB 10.4+, and a host where you can add a system cron line.

**Without Composer.** Download a release, unzip it into `wp-content/plugins/import-scheduler/`, and activate it. The plugin carries its own autoloader; nothing else is needed.

**With Composer.**

```sh
composer require indianopinion/import-scheduler
```

The package type is `wordpress-plugin`, so `composer/installers` places it in your plugins directory. Activate it there as usual. The classes and public functions are also available through Composer's autoloader.

**As a must-use plugin.** Put the plugin folder in `wp-content/mu-plugins/import-scheduler/` and add a one-line loader, `wp-content/mu-plugins/import-scheduler-loader.php` (a copy is in [`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php)):

```php
<?php
require_once WPMU_PLUGIN_DIR . '/import-scheduler/import-scheduler.php';
```

Activation hooks never fire for must-use plugins, so the first tick or the first visit to the screen creates the tables.

## Quick start

1. Register rules. Do it unconditionally, on the `import_scheduler_register` action, so the WP-CLI process that runs the lane sees them too:

```php
add_action('import_scheduler_register', function () {
    // Any callable. It receives a RunContext.
    import_scheduler_register('partner-api', function (\IndianOpinion\ImportScheduler\RunContext $context) {
        $response = wp_safe_remote_get('https://example.com/api/items', ['timeout' => 15]);
        if (is_wp_error($response)) {
            return $response;              // failure, with its message
        }
        // ... import, checking $context->shouldStop() between items ...
        return 12;                         // success, 12 items
    }, ['interval' => 600, 'lane' => 'default']);

    // The reference adapter: one feed through WordPress core's fetch_feed().
    import_scheduler_register_feed('example-news', 'https://example.com/feed/', [
        'interval' => 300,
        'since_last_success' => true,
    ]);
});

// Feed items arrive here. Turning them into posts is your job.
add_action('import_scheduler_item', function (array $item, string $rule_id) {
    // $item: url, title, body, source_name, published, key, feed_url, rule_id
}, 10, 2);
```

2. Add one system cron line per lane, as the user that owns the site files:

```sh
wp import-scheduler cron-line --lane=default --user=www-data --path=/var/www/site
```

prints

```
* * * * * www-data cd /var/www/site && wp import-scheduler tick --lane=default --quiet >> <log file> 2>&1
```

3. Open Tools, Import scheduler, or run `wp import-scheduler status`.

A fuller example that turns feed items into draft posts, with de-duplication, is in [`examples/register-feeds.php`](examples/register-feeds.php). A custom adapter is in [`examples/custom-adapter.php`](examples/custom-adapter.php).

## Writing a runner

- **Give every HTTP call in a runner a timeout.** A call with no timeout can hold its rule lock until the process is killed. The reference adapter sets one (15 seconds by default) on its fetch.
- Check `$context->shouldStop()` between items and return what you have when it is true. It turns true at the rule's deadline (start plus max runtime), on `wp import-scheduler stop <id>`, when the lane is paused, and when the kill switch is on.
- In a long loop call `$context->heartbeat()` now and then. It extends the rule lock and returns `false` if the lock was lost.
- Return a `RunResult`, an item count, `true` or `null` for success; `false` or a `WP_Error` for failure. A thrown exception fails the run and the tick moves on to the next rule.
- Echoed output is captured; the first 500 bytes become the run message when you return no message of your own.
- Do not put personal data in run messages or `$context->log()`. They are stored and shown on the screen.
- If a callable you register here also has its own WP-Cron event, unschedule that event. Otherwise it runs in both places.

## Configuration

Settings live on the Settings tab of the screen, or in the `import_scheduler_settings` option (an array) for automation. Invalid values are rejected and the previous value is kept.

| Setting | Default | Range | Meaning |
|---|---|---|---|
| `enabled` | true | bool | Kill switch. `IMPORT_SCHEDULER_DISABLE` in `wp-config.php` wins. |
| `tick_budget` | 55 | 5 to 3600 s | A tick stops choosing new rules after this. `--budget` overrides it for one call. |
| `expected_cadence` | 60 | 60 to 3600 s | How often cron calls a tick; drives the "no tick seen" warning. |
| `default_max_runtime` | 300 | 10 s to 6 h | For rules that do not set one (or the interval, when shorter). |
| `lock_grace` | 60 | 10 to 600 s | Added to every lock expiry. |
| `lane_concurrency` | `{"default":1}` | 1 to 8 per lane | Slots per lane: ticks that may run on a lane at once. |
| `paused_lanes` | `[]` | lane names | Lanes whose ticks stand down. Use `pause-lane` and `resume-lane`. |
| `pause_after_failures` | 0 | 0 to 100 | Pause a rule after this many failures in a row. 0 never pauses. |
| `backoff_cap` | 21600 | 60 s to 7 days | Ceiling for failure backoff. |
| `memory_ceiling_pct` | 80 | 50 to 95 | A tick stops choosing rules above this share of `memory_limit`. |
| `min_free_disk_mb` | 0 | 0 up | Stand down below this much free disk on the uploads directory. 0 is off. |
| `min_available_memory_mb` | 0 | 0 up | Stand down below this much `MemAvailable` in `/proc/meminfo`. 0 is off. |
| `history_keep` | 50 | 5 to 1000 | Runs kept per rule. |
| `history_days` | 30 | 1 to 365 | Runs older than this are pruned. |
| `wp_cron_fallback` | false | bool | Also tick from WP-Cron, inside page requests. |

Rule arguments for `import_scheduler_register( $id, $runner, $args )`:

| Argument | Default | Rule |
|---|---|---|
| `interval` | required | 60 to 2592000 seconds. Out of range is rejected, never clamped. |
| `label` | the id | Shown on the screen. |
| `lane` | `default` | `^[a-z0-9][a-z0-9_-]{0,31}$` |
| `max_runtime` | 300, or the interval if shorter | 10 to 21600 seconds. Sets the lock expiry and the stop deadline. Nothing is killed. |
| `start_delay` | 0 | First run of a new rule waits this long, to spread many new rules. |
| `jitter` | 0 | Up to a quarter of the interval. Each next run gets a random offset in `[0, jitter]`. |
| `args` | `[]` | Passed to the runner through `$context->args()`. |

The id must match `^[a-z0-9][a-z0-9_-]{0,63}$`. `import_scheduler_register_feed()` also takes `source_name` (default: the feed title, then the feed host), `max_items` (50, at most 500), `timeout` (15 s) and `since_last_success` (false).

### Hooks

| Hook | Kind | Arguments |
|---|---|---|
| `import_scheduler_register` | action | none. Register rules here. |
| `import_scheduler_item` | action | `array $item, string $rule_id, RunContext $context` |
| `import_scheduler_run_started` | action | `array $run` |
| `import_scheduler_run_finished` | action | `array $run` |
| `import_scheduler_rule_paused` | action | `string $rule_id, string $reason` |
| `import_scheduler_tick_finished` | action | `array $summary` (lane, slot, started, ended, runs, failures, skipped, outcome, reason) |
| `import_scheduler_should_tick` | filter | `bool\|string $ok, string $lane`. Return `false` or a reason string to stand down. |
| `import_scheduler_feed_item` | filter | `array\|false $item, string $rule_id`. Return `false` to drop an item. |
| `import_scheduler_capability` | filter | `string $cap`, default `manage_options` |
| `import_scheduler_settings` | filter | `array $settings`. Final say over settings. |
| `import_scheduler_lane_concurrency` | filter | `int $slots, string $lane` |

Public functions: `import_scheduler_register()`, `import_scheduler_register_feed()`, `import_scheduler_get_rule( $id ): ?RuleState` and `import_scheduler_mark_due( $id ): bool`. Each returns `false` (or `null`) instead of throwing.

## WP-CLI

| Command | Does |
|---|---|
| `wp import-scheduler tick [--lane=<lane>] [--budget=<s>] [--dry-run] [--strict]` | One tick. Exit 0 when it ran, found nothing due, found the lane busy or stood down; 1 when the database or tables are missing, or with `--strict` when a run failed. |
| `wp import-scheduler list [--lane=] [--status=] [--format=]` | The rules table. |
| `wp import-scheduler status` | Lanes, locks, last tick per lane, guards, kill switch. |
| `wp import-scheduler run <id> [--ignore-pause]` | Run one rule now in this process. Refuses when its lock is held. Exit 1 on failure. |
| `wp import-scheduler due <id>...` | Make rules due on the next tick. |
| `wp import-scheduler pause <id>... [--reason=]` / `resume <id>...` | Pause and resume rules. |
| `wp import-scheduler interval <id> <seconds>` / `interval <id> --reset` | Override the interval. |
| `wp import-scheduler lane <id> <lane>` | Move a rule to another lane. |
| `wp import-scheduler pause-lane <lane>` / `resume-lane <lane>` | Stop a whole lane, or start it again. |
| `wp import-scheduler stop <id>` | Ask a running rule to stop at its next `shouldStop()`. |
| `wp import-scheduler history <id> [--limit=]` | Run history. |
| `wp import-scheduler unlock <name> [--force]` | Remove an expired lock, or with `--force` a held one. |
| `wp import-scheduler prune [--orphans] [--dry-run]` | Apply retention now; forget orphaned rules. |
| `wp import-scheduler cron-line [--lane=] [--user=] [--path=]` | Print the crontab line. It never writes a file. |

Listing commands take `--format=table|json|csv|yaml|ids`. Unknown rule ids exit 1 with "no such rule".

## The system cron line

One line per lane, run as the user that owns the site files, from a directory that user can read:

```
* * * * * <user> cd <path> && wp import-scheduler tick --lane=default --quiet >> <log file> 2>&1
```

- Never run it as root.
- Send output to a log file, not `/dev/null`. The tick prints one line per run and a summary; `--quiet` keeps failures and the summary.
- No shell lock wrapper is needed. If you add one anyway it must be non-blocking (`flock -n`): a blocking wait queues ticks behind a long run.
- Do not start several lanes, or a lane and other heavy jobs, on the same minute. Offset them.
- Set `DISABLE_WP_CRON` only if another cron line still runs WP-Cron.
- On multisite, add one line per site that has rules, with `--url=<site>`.

`examples/crontab.txt` has a commented example.

## How it works

- **Tick.** Each tick reads the database clock once, runs the pre-flight guards, takes a lane slot (a lock row), finds due rules with one indexed query capped at 200 rows, oldest due first, and runs them one by one until its budget is spent. Rules whose turn did not come stay due and lead the next tick.
- **Locks.** A lock is a row: name, owner token, acquired, expires, heartbeat. Taking one is an insert; if the name is taken, a single conditional update takes it over only when it has expired. Release and renew match the owner. All lock times come from `UNIX_TIMESTAMP()` in the statement, so web nodes with skewed clocks agree. A crashed process never wedges a lane for longer than the expiry. No lock files, no `flock`, no MySQL named locks.
- **Runs.** Each run gets a row, a monotonic duration, a status (`success`, `failure`, `overrun`, `abandoned`, `lock_lost`) and a message. An exception or error in one rule is recorded against that rule only. A shutdown handler closes the run if the runner calls `exit` or hits a fatal error. A run that dies without even that is marked `abandoned` by the next tick once its lock has expired.
- **Next run.** Success: the scheduled time plus the interval, or now plus the interval if that is already past (never a burst of catch-up runs). Overrun: the finish plus a full interval. Failure: the finish plus the interval times 2 to the power (failures minus 1), capped.
- **Overruns.** A long run is never interrupted, never stacked, and never blocks other lanes. A rule slower than its interval on a one-slot lane makes the other rules on that lane wait: give slow rules a lane of their own. Three overruns in a row mark a rule "consistently slow".
- **Storage.** Four tables with the site prefix: rules (state and operator overrides), runs (history), locks, and ticks (one row per lane per tick, kept 7 days). Registration is code; the database holds only state and overrides. Retention runs once a day at the end of a tick.

More detail, and every design decision, is in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). The full behaviour is in [docs/PRD.md](docs/PRD.md).

## Limitations

- It cannot kill a running callable. Overruns are handled by recording, by cooperative stop signals and by lock expiry.
- It does not create posts or de-duplicate items. The reference adapter emits items with a stable key; the consumer decides.
- It never installs or removes a crontab line. Remove the line yourself when you uninstall.
- One slot per tick and one rule run per tick per rule. Multi-slot claims are on the roadmap.
- Single site per tick. On multisite each site runs its own lanes with `--url`.
- Lane names and rule ids are fixed patterns; out-of-range values are rejected, not clamped.

## FAQ

**My rules never run.** Check `wp import-scheduler status`. A lane with no tick in three expected cadences is flagged with the exact cron line to add. Check the kill switch, paused lanes and guards there too.

**A rule is shown as an orphan, or under "Registration problems".** It is registered in wp-admin but not in the WP-CLI process. Register it on `import_scheduler_register` without conditions such as `is_admin()`.

**A lock is stuck.** It is not: every lock expires. If you need it gone sooner, `wp import-scheduler unlock <name>` removes an expired one and `--force` removes a held one. Forcing a lock whose process is still alive can let a second copy run.

**Can I run it without a system cron line?** Turn on `wp_cron_fallback`. Ticks then run inside page requests, which slows visitors and depends on traffic. When a system tick has been seen in the last hour, the fallback does nothing.

**Does it work with an object cache?** Yes. Lock and state reads always go to the database, never to the object cache: a cached lock is a wrong lock.

**How do I stop everything now?** Define `IMPORT_SCHEDULER_DISABLE` as `true` in `wp-config.php`, or turn off `enabled`. Running rules see `shouldStop()` turn true. To stop one lane, `wp import-scheduler pause-lane <lane>`.

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.
