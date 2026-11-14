=== Import Scheduler ===
Contributors: indianopinion
Tags: import, cron, scheduler, wp-cli, feeds
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Runs each import rule on its own interval on a dedicated cron lane with its own database lock, so one slow pass never starves the rest.

== Description ==

Written from scratch; not derived from any third-party plugin.

A site that imports from many sources usually runs every rule in one scheduled pass under one lock. As the rule count grows, the pass outlasts the cron cadence, one hung source holds up everyone, and one fatal error skips every rule after it. Import Scheduler makes each rule its own unit of work: its own interval, its own lock, its own history, run from a system cron line through WP-CLI within a time budget.

* A PHP API to register rules: any callable, or an object implementing the adapter interface.
* `wp import-scheduler tick` runs the due rules of one lane, oldest due first, within a budget, then exits.
* Locks are database rows with an owner and an expiry, compared with the database clock. A crashed process never wedges a lane for longer than the expiry.
* Overruns are recorded, never stacked, never replayed as a burst.
* An exception in one rule is recorded against that rule; the tick moves on.
* One screen under Tools shows every rule's lane, interval, status, last run, duration, result, next run and failures, and each lane's last tick and locks.
* WP-CLI commands to list, inspect, run, pause, resume, re-time, move, stop and unlock rules, and to pause a whole lane.
* A reference adapter for WordPress core's own feed fetching that emits each item on an action. Turning items into posts is up to you.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Upload the plugin to `wp-content/plugins/import-scheduler/` and activate it.
2. Register rules on the `import_scheduler_register` action, unconditionally.
3. Add one system cron line per lane, as the user that owns the site files. `wp import-scheduler cron-line --lane=default` prints it.
4. Open Tools, Import scheduler.

To run it as a must-use plugin, put the folder in `wp-content/mu-plugins/` and add a one-line loader that requires `import-scheduler/import-scheduler.php`.

== Frequently Asked Questions ==

= Does it create posts? =

No. Rules run your code; the feed adapter emits normalised items on the `import_scheduler_item` action with a stable key for de-duplication.

= Can it run without a system cron line? =

Turn on the WP-Cron fallback in settings. Ticks then run inside page requests. A system cron line is better.

= How do I stop everything? =

Define `IMPORT_SCHEDULER_DISABLE` as true in `wp-config.php`, or turn off "enabled" in settings. To stop one lane, `wp import-scheduler pause-lane <lane>`.

= What happens on uninstall? =

The plugin's four tables and its options are deleted, on every site of a network. Remove the system cron line yourself.

== Changelog ==

= 0.1.0 =
* First release.
