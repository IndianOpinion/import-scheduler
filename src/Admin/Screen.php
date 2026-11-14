<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Admin;

use IndianOpinion\ImportScheduler\Cli\Command;
use IndianOpinion\ImportScheduler\Exception\ImportSchedulerException;
use IndianOpinion\ImportScheduler\Exception\LockException;
use IndianOpinion\ImportScheduler\Installer;
use IndianOpinion\ImportScheduler\Plugin;
use IndianOpinion\ImportScheduler\RuleState;
use IndianOpinion\ImportScheduler\Settings;
use IndianOpinion\ImportScheduler\Tick;

/**
 * Tools > Import scheduler. Lanes, rules, history and settings on one
 * screen. Every action is a POST with a nonce and a capability check. The
 * screen never runs a rule: "Run now" only marks it due.
 */
final class Screen
{
    public const SLUG = 'import-scheduler';
    public const ACTION = 'import_scheduler';
    public const NONCE = 'import_scheduler_action';
    public const PER_PAGE = 100;
    public const COLUMNS = [
        'lane' => 'Lane',
        'interval' => 'Interval',
        'status' => 'Status',
        'last_run' => 'Last run',
        'last_duration' => 'Last duration',
        'last_result' => 'Last result',
        'next_run' => 'Next run',
        'failures' => 'Failures',
        'median' => 'Median (20)',
    ];
    public const ACTIONS = [
        'run_now', 'pause', 'resume', 'interval', 'reset_interval', 'lane', 'forget',
        'unlock', 'pause_lane', 'resume_lane', 'save_settings',
    ];

    private bool $utc = false;

    public function __construct(private Plugin $plugin)
    {
    }

    public static function capability(): string
    {
        $cap = apply_filters('import_scheduler_capability', 'manage_options');
        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    public function addPage(): void
    {
        $hook = add_management_page('Import scheduler', 'Import scheduler', self::capability(), self::SLUG, [$this, 'page']);
        if (is_string($hook) && $hook !== '') {
            add_filter('manage_' . $hook . '_columns', [$this, 'columns']);
        }
    }

    /**
     * Columns the Screen Options panel can hide.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return ['label' => 'Rule'] + self::COLUMNS;
    }

    /**
     * Page callback.
     */
    public function page(): void
    {
        $query = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $query[$key] = sanitize_text_field(wp_unslash($value));
            }
        }
        echo $this->render($query); // phpcs:ignore -- every part is escaped where it is built.
    }

    /**
     * Build the page. Everything dynamic is escaped here.
     *
     * @param array<string, string> $query
     */
    public function render(array $query): string
    {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html('You do not have permission to view this page.'), '', ['response' => 403]);
        }
        $this->utc = ($query['utc'] ?? '') === '1';
        $html = '<div class="wrap"><h1>Import scheduler</h1>';
        $html .= $this->notice();
        try {
            $operator = $this->plugin->operator();
            $rules = $operator->rules(true);
            $now = $this->plugin->storage()->now();
        } catch (ImportSchedulerException $e) {
            if ($this->plugin->install()) {
                $operator = $this->plugin->operator();
                $rules = $operator->rules(true);
                $now = $this->plugin->storage()->now();
            } else {
                return $html . '<div class="notice notice-error"><p>' . esc_html('The import scheduler tables are missing and could not be created: ' . $e->getMessage()) . '</p></div></div>';
            }
        }
        $missing = Installer::missingColumns();
        if ($missing !== []) {
            $html .= '<div class="notice notice-error"><p>' . esc_html('Missing database columns: ' . Installer::describe($missing) . '. Deactivate and activate the plugin, or add them by hand.') . '</p></div>';
        }
        $settings = $this->plugin->settings();
        if (!$settings->enabled()) {
            $html .= '<div class="notice notice-warning"><p>' . esc_html($settings->constantDisabled()
                ? 'The kill switch is on: IMPORT_SCHEDULER_DISABLE is set in wp-config.php. Ticks exit without running anything.'
                : 'The kill switch is on. Ticks exit without running anything.') . '</p></div>';
        }
        if ($settings->bool('wp_cron_fallback')) {
            $html .= '<div class="notice notice-warning"><p>' . esc_html('The WP-Cron fallback is on: ticks may run inside page requests. A system cron line is better; the fallback stands aside while system ticks are seen.') . '</p></div>';
        }
        $tab = $query['tab'] ?? '';
        $html .= '<nav class="nav-tab-wrapper">';
        $html .= '<a class="nav-tab' . ($tab === '' ? ' nav-tab-active' : '') . '" href="' . esc_url($this->url()) . '">Rules</a>';
        $html .= '<a class="nav-tab' . ($tab === 'settings' ? ' nav-tab-active' : '') . '" href="' . esc_url($this->url(['tab' => 'settings'])) . '">Settings</a>';
        $html .= '</nav>';
        if ($tab === 'settings') {
            return $html . $this->settingsTab($settings) . '</div>';
        }
        if (isset($query['history']) && $query['history'] !== '') {
            return $html . $this->history($query['history'], $settings) . '</div>';
        }
        if (isset($query['confirm_unlock']) && $query['confirm_unlock'] !== '') {
            $html .= $this->confirmUnlock($query['confirm_unlock']);
        }
        $html .= $this->lanes($rules, $now, $settings);
        $html .= $this->problems($rules);
        $html .= $this->rulesTable($rules, $now, $settings, $query);
        $html .= '<p class="description">' . esc_html('Times are in the site time zone; hover for UTC. Deactivating the plugin keeps its tables, settings and history and removes only WP-Cron fallback events. Remove the system cron line yourself.') . '</p>';
        return $html . '</div>';
    }

    /**
     * admin-post handler.
     */
    public function handlePost(): void
    {
        $post = [];
        foreach ($_POST as $key => $value) {
            if (is_string($key)) {
                $post[$key] = is_array($value) ? array_map('strval', wp_unslash($value)) : sanitize_text_field(wp_unslash((string) $value));
            }
        }
        $url = $this->process($post);
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Check the capability and nonce, apply one action, return where to go.
     *
     * @param array<string, mixed> $post
     */
    public function process(array $post): string
    {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html('You do not have permission to do this.'), '', ['response' => 403]);
        }
        $nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE)) {
            wp_die(esc_html('The link has expired. Go back and try again.'), '', ['response' => 403]);
        }
        $action = isset($post['do']) && is_string($post['do']) ? $post['do'] : '';
        if (!in_array($action, self::ACTIONS, true)) {
            wp_die(esc_html('Unknown action.'), '', ['response' => 400]);
        }
        $id = isset($post['id']) && is_string($post['id']) ? $post['id'] : '';
        $operator = $this->plugin->operator();
        $back = [];
        try {
            switch ($action) {
                case 'run_now':
                    $operator->markDue($id);
                    $this->setNotice('success', sprintf('%s is due and runs on its lane\'s next tick.', $id));
                    break;
                case 'pause':
                    $operator->pause($id, is_string($post['reason'] ?? null) ? $post['reason'] : '');
                    $this->setNotice('success', $id . ' paused.');
                    break;
                case 'resume':
                    $operator->resume($id);
                    $this->setNotice('success', $id . ' resumed.');
                    break;
                case 'interval':
                    $seconds = isset($post['seconds']) && is_string($post['seconds']) && ctype_digit($post['seconds']) ? (int) $post['seconds'] : 0;
                    $operator->setInterval($id, $seconds);
                    $this->setNotice('success', sprintf('%s now runs every %d seconds.', $id, $seconds));
                    break;
                case 'reset_interval':
                    $operator->resetInterval($id);
                    $this->setNotice('success', $id . ' is back on its registered interval.');
                    break;
                case 'lane':
                    $lane = isset($post['lane']) && is_string($post['lane']) ? $post['lane'] : '';
                    $operator->moveLane($id, $lane);
                    $this->setNotice('success', sprintf('%s moved to lane %s. Make sure that lane has a cron line.', $id, $lane));
                    break;
                case 'forget':
                    $operator->forget($id);
                    $this->setNotice('success', $id . ' and its history were forgotten.');
                    break;
                case 'unlock':
                    $name = isset($post['name']) && is_string($post['name']) ? $post['name'] : '';
                    $force = ($post['force'] ?? '') === '1';
                    try {
                        if ($this->plugin->locks()->unlock($name, $force)) {
                            $this->setNotice('success', 'Removed lock ' . $name . '.');
                        } else {
                            $this->setNotice('warning', 'No such lock: ' . $name . '.');
                        }
                    } catch (LockException $e) {
                        $back['confirm_unlock'] = $name;
                    }
                    break;
                case 'pause_lane':
                case 'resume_lane':
                    $lane = isset($post['lane']) && is_string($post['lane']) ? $post['lane'] : '';
                    $errors = $operator->setLanePaused($lane, $action === 'pause_lane');
                    $this->setNotice($errors === [] ? 'success' : 'error', $errors === [] ? sprintf('Lane %s %s.', $lane, $action === 'pause_lane' ? 'paused' : 'resumed') : implode(' ', $errors));
                    break;
                case 'save_settings':
                    $errors = $operator->saveSettings($this->settingsInput($post));
                    $this->setNotice($errors === [] ? 'success' : 'error', $errors === [] ? 'Settings saved.' : 'Some values were rejected and kept their previous value: ' . implode(' ', $errors));
                    $back['tab'] = 'settings';
                    break;
            }
        } catch (ImportSchedulerException $e) {
            $this->setNotice('error', $e->getMessage());
        }
        return $this->url($back);
    }

    /**
     * @param array<string, string> $args
     */
    public function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('tools.php'));
    }

    public function formatTime(?int $time, int $now): string
    {
        if ($time === null) {
            return '&mdash;';
        }
        $utc = gmdate('Y-m-d H:i:s', $time) . ' UTC';
        $shown = $this->utc ? $utc : wp_date('Y-m-d H:i:s', $time);
        $delta = $time - $now;
        $relative = $delta === 0 ? 'now' : ($delta > 0 ? 'in ' . self::human($delta) : self::human(-$delta) . ' ago');
        return '<span title="' . esc_attr($utc) . '">' . esc_html($relative) . '<br><small>' . esc_html((string) $shown) . '</small></span>';
    }

    public static function human(int $seconds): string
    {
        if ($seconds < 120) {
            return $seconds . 's';
        }
        if ($seconds < 7200) {
            return intdiv($seconds, 60) . ' min';
        }
        if ($seconds < 172800) {
            return intdiv($seconds, 3600) . ' h';
        }
        return intdiv($seconds, 86400) . ' days';
    }

    /**
     * @param array<string, RuleState> $rules
     */
    private function lanes(array $rules, int $now, Settings $settings): string
    {
        $storage = $this->plugin->storage();
        $ticks = $storage->latestTicks();
        $locks = $storage->allLocks();
        $cadence = $settings->int('expected_cadence');
        $html = '<h2>Lanes</h2><table class="widefat striped"><thead><tr><th>Lane</th><th>Slots</th><th>Rules</th><th>Last tick</th><th>Outcome</th><th>Locks</th><th>Actions</th></tr></thead><tbody>';
        $warnings = '';
        foreach ($this->plugin->operator()->lanes($rules) as $lane) {
            $count = count(array_filter($rules, static fn (RuleState $r): bool => $r->lane === $lane && $r->registered));
            $tick = $ticks[$lane] ?? null;
            $laneLocks = '';
            foreach ($locks as $lock) {
                if (strpos($lock['name'], 'lane:' . $lane . ':') !== 0) {
                    continue;
                }
                $laneLocks .= '<div>' . esc_html($lock['name'] . ' held by ' . substr($lock['owner'], 0, 8)) . ', expires ' . $this->formatTime($lock['expires'], $now)
                    . $this->button('unlock', 'Unlock', ['name' => $lock['name']], 'link') . '</div>';
            }
            $paused = $settings->isLanePaused($lane);
            $html .= '<tr><td><strong>' . esc_html($lane) . '</strong>' . ($paused ? ' <em>' . esc_html('(paused)') . '</em>' : '') . '</td>'
                . '<td>' . esc_html((string) $settings->laneConcurrency($lane)) . '</td>'
                . '<td>' . esc_html((string) $count) . '</td>'
                . '<td>' . ($tick !== null ? $this->formatTime($tick['started'], $now) : esc_html('never')) . '</td>'
                . '<td>' . esc_html($tick !== null ? Command::outcome($tick['outcome'], $tick['runs'], $tick['reason']) : '') . '</td>'
                . '<td>' . ($laneLocks !== '' ? $laneLocks : esc_html('free')) . '</td>'
                . '<td>' . ($paused
                    ? $this->button('resume_lane', 'Resume lane', ['lane' => $lane])
                    : $this->button('pause_lane', 'Pause lane', ['lane' => $lane])) . '</td></tr>';
            if ($count > 0 && ($tick === null || $tick['started'] < $now - 3 * $cadence)) {
                $line = Command::cronLineFor($lane, '<user>', defined('ABSPATH') ? rtrim((string) constant('ABSPATH'), '/') : '<path>');
                $warnings .= '<div class="notice notice-warning inline"><p>'
                    . esc_html(sprintf('Lane %s: no tick seen since %s. Check the system cron line.', $lane, $tick !== null ? gmdate('Y-m-d H:i:s', $tick['started']) . ' UTC' : 'the plugin was installed'))
                    . '</p><p><code>' . esc_html($line) . '</code></p></div>';
            }
        }
        return $html . '</tbody></table>' . $warnings;
    }

    /**
     * @param array<string, RuleState> $rules
     */
    private function problems(array $rules): string
    {
        $items = [];
        foreach ($this->plugin->registry()->problems() as $problem) {
            $items[] = $problem;
        }
        foreach ($this->plugin->operator()->missingFromTick($rules) as $id) {
            $items[] = sprintf('%s is registered in wp-admin but the last tick of its lane did not see it. Register rules unconditionally, not only in wp-admin.', $id);
        }
        if ($items === []) {
            return '';
        }
        $html = '<div class="notice notice-error inline"><p><strong>' . esc_html('Registration problems') . '</strong></p><ul>';
        foreach ($items as $item) {
            $html .= '<li>' . esc_html($item) . '</li>';
        }
        return $html . '</ul></div>';
    }

    /**
     * @param array<string, RuleState> $rules
     * @param array<string, string>    $query
     */
    private function rulesTable(array $rules, int $now, Settings $settings, array $query): string
    {
        $laneFilter = $query['lane'] ?? '';
        $statusFilter = $query['status'] ?? '';
        $search = strtolower(trim($query['s'] ?? ''));
        $orderby = $query['orderby'] ?? 'next_run';
        $order = ($query['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $medians = $this->plugin->operator()->medians(array_keys($rules));
        $rows = [];
        foreach ($rules as $id => $rule) {
            $status = $rule->status($now);
            if ($laneFilter !== '' && $rule->lane !== $laneFilter) {
                continue;
            }
            if ($statusFilter !== '' && $status !== $statusFilter) {
                continue;
            }
            if ($search !== '' && strpos(strtolower($id . ' ' . $rule->label), $search) === false) {
                continue;
            }
            $rows[] = ['rule' => $rule, 'status' => $status, 'median' => $medians[$id] ?? null];
        }
        $keys = [
            'label' => static fn (array $r) => $r['rule']->label,
            'lane' => static fn (array $r) => $r['rule']->lane,
            'interval' => static fn (array $r) => $r['rule']->interval,
            'status' => static fn (array $r) => $r['status'],
            'last_run' => static fn (array $r) => $r['rule']->lastStart ?? 0,
            'last_duration' => static fn (array $r) => $r['rule']->lastDurationMs ?? -1,
            'last_result' => static fn (array $r) => $r['rule']->lastStatus,
            'next_run' => static fn (array $r) => $r['rule']->nextRun ?? 0,
            'failures' => static fn (array $r) => $r['rule']->consecutiveFailures,
            'median' => static fn (array $r) => $r['median'] ?? -1,
        ];
        $key = $keys[$orderby] ?? $keys['next_run'];
        usort($rows, static function (array $a, array $b) use ($key, $order): int {
            $cmp = [$key($a), $a['rule']->id] <=> [$key($b), $b['rule']->id];
            return $order === 'desc' ? -$cmp : $cmp;
        });
        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, (int) ($query['paged'] ?? 1)));
        $rows = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $hidden = $this->hiddenColumns();

        $html = '<h2>Rules</h2><form method="get" action="' . esc_url(admin_url('tools.php')) . '">'
            . '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">'
            . '<select name="lane"><option value="">' . esc_html('All lanes') . '</option>';
        foreach ($this->plugin->operator()->lanes($rules) as $lane) {
            $html .= '<option value="' . esc_attr($lane) . '"' . ($lane === $laneFilter ? ' selected' : '') . '>' . esc_html($lane) . '</option>';
        }
        $html .= '</select> <select name="status"><option value="">' . esc_html('All statuses') . '</option>';
        foreach ([RuleState::STATUS_IDLE, RuleState::STATUS_DUE, RuleState::STATUS_RUNNING, RuleState::STATUS_PAUSED, RuleState::STATUS_FAILING, RuleState::STATUS_SLOW, RuleState::STATUS_ORPHAN] as $status) {
            $html .= '<option value="' . esc_attr($status) . '"' . ($status === $statusFilter ? ' selected' : '') . '>' . esc_html($status) . '</option>';
        }
        $html .= '</select> <input type="search" name="s" value="' . esc_attr($query['s'] ?? '') . '" placeholder="' . esc_attr('Search id or label') . '">'
            . ' <label><input type="checkbox" name="utc" value="1"' . ($this->utc ? ' checked' : '') . '> UTC</label>'
            . ' <button class="button">' . esc_html('Filter') . '</button></form>';

        $html .= '<table class="widefat striped import-scheduler-rules"><thead><tr>';
        foreach ($this->columns() as $column => $title) {
            if (in_array($column, $hidden, true)) {
                continue;
            }
            $next = ($orderby === $column && $order === 'asc') ? 'desc' : 'asc';
            $html .= '<th scope="col" class="column-' . esc_attr($column) . '"><a href="' . esc_url($this->url(array_filter([
                'orderby' => $column, 'order' => $next, 'lane' => $laneFilter, 'status' => $statusFilter, 's' => $query['s'] ?? '', 'utc' => $this->utc ? '1' : '',
            ]))) . '">' . esc_html($title) . '</a></th>';
        }
        $html .= '</tr></thead><tbody>';
        if ($rows === []) {
            $html .= '<tr><td colspan="' . (count($this->columns()) - count($hidden)) . '">' . esc_html('No rules match.') . '</td></tr>';
        }
        foreach ($rows as $row) {
            $html .= $this->ruleRow($row['rule'], $row['status'], $row['median'], $now, $settings, $hidden);
        }
        $html .= '</tbody></table>';
        if ($pages > 1) {
            $html .= '<p class="tablenav-pages">';
            for ($p = 1; $p <= $pages; $p++) {
                $html .= $p === $page ? ' <strong>' . $p . '</strong>' : ' <a href="' . esc_url($this->url(array_filter([
                    'paged' => (string) $p, 'orderby' => $orderby, 'order' => $order, 'lane' => $laneFilter, 'status' => $statusFilter, 's' => $query['s'] ?? '',
                ]))) . '">' . $p . '</a>';
            }
            $html .= '</p>';
        }
        return $html;
    }

    /**
     * @param list<string> $hidden
     */
    private function ruleRow(RuleState $rule, string $status, ?int $median, int $now, Settings $settings, array $hidden): string
    {
        $cells = [];
        $actions = [];
        if ($rule->registered) {
            $actions[] = $this->button('run_now', 'Run now', ['id' => $rule->id], 'link');
            $actions[] = $rule->paused
                ? $this->button('resume', 'Resume', ['id' => $rule->id], 'link')
                : $this->button('pause', 'Pause', ['id' => $rule->id], 'link');
            if ($rule->intervalOverride !== null) {
                $actions[] = $this->button('reset_interval', 'Reset interval', ['id' => $rule->id], 'link');
            }
            $actions[] = $this->inlineForm('interval', 'Change interval', $rule->id, 'seconds', (string) $rule->interval, 'number');
            $actions[] = $this->inlineForm('lane', 'Move to lane', $rule->id, 'lane', $rule->lane, 'text');
        } else {
            $actions[] = $this->button('forget', 'Forget', ['id' => $rule->id], 'link');
        }
        $actions[] = '<a href="' . esc_url($this->url(['history' => $rule->id])) . '">' . esc_html('View history') . '</a>';
        $cells['label'] = '<strong>' . esc_html($rule->label) . '</strong><br><code>' . esc_html($rule->id) . '</code><div class="row-actions visible">' . implode(' | ', $actions) . '</div>';
        $cells['lane'] = esc_html($rule->lane) . ($rule->laneOverride !== null ? ' <em>' . esc_html('(overridden)') . '</em>' : '');
        $interval = esc_html(self::human($rule->interval)) . ($rule->intervalOverride !== null ? ' <em>' . esc_html('(overridden)') . '</em>' : '');
        if ($rule->interval < $settings->int('expected_cadence')) {
            $interval .= '<br><small>' . esc_html('effective cadence limited by cron') . '</small>';
        }
        $cells['interval'] = $interval;
        $statusHtml = esc_html($status);
        if ($rule->paused && $rule->pauseReason !== '') {
            $statusHtml .= '<br><small>' . esc_html($rule->pauseReason) . '</small>';
        }
        if ($rule->isConsistentlySlow()) {
            $statusHtml .= '<br><small>' . esc_html('consistently slow: raise its max runtime or give it a lane of its own') . '</small>';
        }
        if ($rule->lockLost()) {
            $statusHtml .= '<br><small>' . esc_html('warning: the last run lost its lock') . '</small>';
        }
        $cells['status'] = $statusHtml;
        $cells['last_run'] = $this->formatTime($rule->lastStart, $now);
        $cells['last_duration'] = $rule->lastDurationMs !== null ? esc_html(sprintf('%.1fs', $rule->lastDurationMs / 1000)) : '&mdash;';
        $result = $rule->lastStatus !== '' ? $rule->lastStatus : '';
        if ($rule->lastItems !== null) {
            $result .= ', ' . $rule->lastItems . ' items';
        }
        $cells['last_result'] = esc_html($result) . ($rule->lastMessage !== '' ? '<br><small>' . esc_html($rule->lastMessage) . '</small>' : '');
        $cells['next_run'] = ($rule->nextRun === null || $rule->nextRun <= $now) && !$rule->paused && $rule->registered
            ? esc_html('now')
            : $this->formatTime($rule->nextRun, $now);
        $cells['failures'] = esc_html((string) $rule->consecutiveFailures);
        $cells['median'] = $median !== null ? esc_html(sprintf('%.1fs', $median / 1000)) : '&mdash;';
        $html = '<tr class="' . ($rule->registered ? '' : 'import-scheduler-orphan" style="opacity:.6') . '">';
        foreach (array_keys($this->columns()) as $column) {
            if (!in_array($column, $hidden, true)) {
                $html .= '<td class="column-' . esc_attr($column) . '">' . $cells[$column] . '</td>';
            }
        }
        return $html . '</tr>';
    }

    private function history(string $id, Settings $settings): string
    {
        $now = $this->plugin->storage()->now();
        try {
            $rule = $this->plugin->operator()->rule($id);
        } catch (ImportSchedulerException $e) {
            return '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
        $runs = $this->plugin->storage()->runsForRule($id, $settings->int('history_keep'));
        $html = '<h2>' . esc_html('History: ' . $rule->label . ' (' . $rule->id . ')') . '</h2>'
            . '<p><a href="' . esc_url($this->url()) . '">' . esc_html('Back to all rules') . '</a></p>'
            . '<table class="widefat striped"><thead><tr><th>Start</th><th>Duration</th><th>Status</th><th>Items</th><th>Message</th></tr></thead><tbody>';
        if ($runs === []) {
            $html .= '<tr><td colspan="5">' . esc_html('No runs recorded yet.') . '</td></tr>';
        }
        foreach ($runs as $run) {
            $html .= '<tr><td>' . $this->formatTime($run['started'], $now) . '</td>'
                . '<td>' . ($run['duration_ms'] !== null ? esc_html(sprintf('%.1fs', $run['duration_ms'] / 1000)) : '&mdash;') . '</td>'
                . '<td>' . esc_html($run['status'] . ($run['original_status'] !== '' ? ' (' . $run['original_status'] . ')' : '')) . '</td>'
                . '<td>' . esc_html($run['items'] === null ? '' : (string) $run['items']) . '</td>'
                . '<td>' . esc_html($run['message']) . ($run['log'] !== '' ? '<details><summary>' . esc_html('log') . '</summary><pre>' . esc_html($run['log']) . '</pre></details>' : '') . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function confirmUnlock(string $name): string
    {
        $lock = $this->plugin->storage()->getLock($name);
        if ($lock === null) {
            return '';
        }
        return '<div class="notice notice-warning"><p>' . esc_html(sprintf(
            'Lock %s is held by %s until %s UTC. Removing it while its process is alive can let a second copy run. Remove it anyway?',
            $name,
            substr($lock['owner'], 0, 8),
            gmdate('Y-m-d H:i:s', $lock['expires'])
        )) . '</p><p>' . $this->button('unlock', 'Yes, remove the lock', ['name' => $name, 'force' => '1']) . ' <a href="' . esc_url($this->url()) . '">' . esc_html('Cancel') . '</a></p></div>';
    }

    private function settingsTab(Settings $settings): string
    {
        $values = $settings->toArray();
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="do" value="save_settings">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . '<table class="form-table">';
        $html .= $this->checkbox('enabled', 'Enabled', (bool) $values['enabled'], 'Off is the kill switch. IMPORT_SCHEDULER_DISABLE in wp-config.php wins.');
        foreach (Settings::RANGES as $key => [$min, $max]) {
            $html .= '<tr><th scope="row"><label for="is-' . esc_attr($key) . '">' . esc_html($key) . '</label></th><td>'
                . '<input type="number" id="is-' . esc_attr($key) . '" name="settings[' . esc_attr($key) . ']" value="' . esc_attr((string) $values[$key]) . '" min="' . esc_attr((string) $min) . '"' . ($max !== PHP_INT_MAX ? ' max="' . esc_attr((string) $max) . '"' : '') . '>'
                . '</td></tr>';
        }
        $lanes = [];
        foreach ((array) $values['lane_concurrency'] as $lane => $slots) {
            $lanes[] = $lane . '=' . (is_scalar($slots) ? (string) $slots : '1');
        }
        $html .= '<tr><th scope="row"><label for="is-lane-concurrency">lane_concurrency</label></th><td>'
            . '<textarea id="is-lane-concurrency" name="settings[lane_concurrency]" rows="3" cols="30">' . esc_html(implode("\n", $lanes)) . '</textarea>'
            . '<p class="description">' . esc_html('One lane=slots per line, 1 to 8 slots.') . '</p></td></tr>';
        $html .= $this->checkbox('wp_cron_fallback', 'WP-Cron fallback', (bool) $values['wp_cron_fallback'], 'Runs ticks inside page requests. A system cron line is better; when one has ticked in the last hour the fallback does nothing.');
        $html .= '</table>';
        if ((bool) $values['wp_cron_fallback']) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html('The WP-Cron fallback is on. Ticks run inside page requests, which slows visitors and depends on traffic. Add a system cron line instead.') . '</p></div>';
        }
        return $html . '<p><button class="button button-primary">' . esc_html('Save settings') . '</button></p></form>';
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function settingsInput(array $post): array
    {
        $raw = isset($post['settings']) && is_array($post['settings']) ? $post['settings'] : [];
        $input = [];
        foreach (array_keys(Settings::RANGES) as $key) {
            if (isset($raw[$key])) {
                $input[$key] = trim((string) $raw[$key]);
            }
        }
        $input['enabled'] = isset($raw['enabled']) ? '1' : '0';
        $input['wp_cron_fallback'] = isset($raw['wp_cron_fallback']) ? '1' : '0';
        if (isset($raw['lane_concurrency'])) {
            $map = [];
            foreach (preg_split('/\R/', (string) $raw['lane_concurrency']) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = explode('=', $line, 2);
                $map[trim($parts[0])] = trim($parts[1] ?? '');
            }
            $input['lane_concurrency'] = $map;
        }
        return $input;
    }

    private function checkbox(string $key, string $label, bool $checked, string $help): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="settings[' . esc_attr($key) . ']" value="1"' . ($checked ? ' checked' : '') . '> '
            . esc_html($help) . '</label></td></tr>';
    }

    /**
     * A one-button POST form with a nonce.
     *
     * @param array<string, string> $fields
     */
    private function button(string $action, string $label, array $fields, string $style = 'secondary'): string
    {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="do" value="' . esc_attr($action) . '">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false);
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        $class = $style === 'link' ? 'button-link' : 'button button-' . $style;
        return $html . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    private function inlineForm(string $action, string $label, string $id, string $field, string $value, string $type): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="do" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="id" value="' . esc_attr($id) . '">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . '<input type="' . esc_attr($type) . '" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '" class="small-text" aria-label="' . esc_attr($label) . '">'
            . '<button type="submit" class="button-link">' . esc_html($label) . '</button></form>';
    }

    /**
     * @return list<string>
     */
    private function hiddenColumns(): array
    {
        if (!function_exists('get_current_screen') || !function_exists('get_hidden_columns')) {
            return [];
        }
        $screen = get_current_screen();
        if ($screen === null) {
            return [];
        }
        $hidden = get_hidden_columns($screen);
        return array_values(array_filter($hidden, static fn ($c): bool => is_string($c) && $c !== 'label'));
    }

    private function setNotice(string $type, string $message): void
    {
        set_transient('import_scheduler_notice_' . get_current_user_id(), ['type' => $type, 'message' => $message], 60);
    }

    private function notice(): string
    {
        $key = 'import_scheduler_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice) || !isset($notice['type'], $notice['message'])) {
            return '';
        }
        delete_transient($key);
        $type = in_array($notice['type'], ['success', 'warning', 'error'], true) ? $notice['type'] : 'info';
        return '<div class="notice notice-' . esc_attr((string) $type) . ' is-dismissible"><p>' . esc_html((string) $notice['message']) . '</p></div>';
    }
}
