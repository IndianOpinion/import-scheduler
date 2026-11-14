# import-scheduler

Runs each import rule on its own interval on a dedicated cron lane with its own lock, so one slow pass never starves the rest, with per-rule last-run, next-run and duration shown on one screen.
