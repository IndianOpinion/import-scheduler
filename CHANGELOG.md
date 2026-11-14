# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Rule registration API: `import_scheduler_register()`, `import_scheduler_register_feed()`, `import_scheduler_get_rule()` and `import_scheduler_mark_due()`, with strict validation of ids, lanes, intervals and runtimes.
- `wp import-scheduler tick`: runs a lane's due rules oldest first within a time budget, with pre-flight guards, lane slots, a memory ceiling, `--dry-run` and `--strict`.
- Database locks with an owner and an expiry, taken with one conditional statement and compared with the database clock.
- Per-run history with statuses `success`, `failure`, `overrun`, `abandoned` and `lock_lost`; a shutdown handler for runners that exit or die.
- Next-run rules: no catch-up bursts, a full rest after an overrun, exponential failure backoff to a cap, optional jitter and start delay, optional auto-pause.
- Per-lane pause switch beside the global kill switch and the `IMPORT_SCHEDULER_DISABLE` constant.
- One row per lane per tick, kept seven days, for the screen and `status`.
- Tools, Import scheduler screen: lanes, rules, history and settings, with filters, sorting, search, pagination, screen options and nonce-checked actions.
- WP-CLI commands: `list`, `status`, `run`, `due`, `pause`, `resume`, `interval`, `lane`, `pause-lane`, `resume-lane`, `stop`, `history`, `unlock`, `prune` and `cron-line`.
- Reference adapter for WordPress core `fetch_feed()`, emitting normalised items on `import_scheduler_item`.
- Optional WP-Cron fallback that stands aside when a system cron tick has run in the last hour.
- Daily retention, schema column verification, uninstall on every site of a network.

[Unreleased]: https://github.com/IndianOpinion/import-scheduler/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/import-scheduler/releases/tag/v0.1.0
