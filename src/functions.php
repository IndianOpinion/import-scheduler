<?php

/**
 * Public functions. Each catches its own exceptions, so a bad registration
 * in some plugin never breaks the site.
 */

declare(strict_types=1);

use IndianOpinion\ImportScheduler\Adapter;
use IndianOpinion\ImportScheduler\Exception\ImportSchedulerException;
use IndianOpinion\ImportScheduler\Feed\FeedAdapter;
use IndianOpinion\ImportScheduler\Plugin;
use IndianOpinion\ImportScheduler\RuleState;

if (!function_exists('import_scheduler_register')) {
    /**
     * Register an import rule.
     *
     * Args: interval (int seconds, required, 60 to 2592000), label, lane
     * (default "default"), max_runtime (10 to 21600), start_delay, jitter
     * (at most a quarter of the interval), args (array for the runner).
     *
     * @param callable|Adapter     $runner
     * @param array<string, mixed> $args
     * @return bool False when the registration was rejected.
     */
    function import_scheduler_register(string $id, $runner, array $args = []): bool
    {
        try {
            Plugin::instance()->registry()->register($id, $runner, $args);
            return true;
        } catch (ImportSchedulerException $e) {
            if (function_exists('_doing_it_wrong')) {
                _doing_it_wrong(__FUNCTION__, esc_html($e->getMessage()), '0.1.0');
            }
            return false;
        }
    }
}

if (!function_exists('import_scheduler_register_feed')) {
    /**
     * Register a rule that reads one feed through fetch_feed() and emits
     * each item on the import_scheduler_item action.
     *
     * Extra args: source_name, max_items (default 50, at most 500), timeout
     * (seconds, default 15), since_last_success (default false).
     *
     * @param array<string, mixed> $args
     */
    function import_scheduler_register_feed(string $id, string $feed_url, array $args = []): bool
    {
        $feedKeys = ['source_name', 'max_items', 'timeout', 'since_last_success'];
        $feedArgs = array_intersect_key($args, array_flip($feedKeys));
        $ruleArgs = array_diff_key($args, array_flip($feedKeys));
        try {
            $label = isset($ruleArgs['label']) && is_string($ruleArgs['label']) ? $ruleArgs['label'] : '';
            $adapter = new FeedAdapter($feed_url, $feedArgs, $label);
            Plugin::instance()->registry()->register($id, $adapter, $ruleArgs);
            return true;
        } catch (ImportSchedulerException $e) {
            if (function_exists('_doing_it_wrong')) {
                _doing_it_wrong(__FUNCTION__, esc_html($e->getMessage()), '0.1.0');
            }
            return false;
        }
    }
}

if (!function_exists('import_scheduler_get_rule')) {
    /**
     * Read-only snapshot of a rule, or null when it is neither registered
     * nor stored.
     */
    function import_scheduler_get_rule(string $id): ?RuleState
    {
        try {
            return Plugin::instance()->operator()->rule($id);
        } catch (ImportSchedulerException $e) {
            return null;
        }
    }
}

if (!function_exists('import_scheduler_mark_due')) {
    /**
     * Make a rule due on its lane's next tick. Does not bypass the lane, the
     * lock or a pause.
     */
    function import_scheduler_mark_due(string $id): bool
    {
        try {
            Plugin::instance()->operator()->markDue($id);
            return true;
        } catch (ImportSchedulerException $e) {
            return false;
        }
    }
}
