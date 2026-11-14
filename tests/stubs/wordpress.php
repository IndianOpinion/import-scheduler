<?php

/**
 * A small stand-in for the WordPress functions this plugin calls, so the
 * suite runs without a WordPress install. State lives in $GLOBALS['wp_stub']
 * and is reset before every test.
 */

declare(strict_types=1);

// phpcs:disable

if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/example/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class WpStubDie extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

final class WpStub
{
    public static function reset(): void
    {
        $GLOBALS['wp_stub'] = [
            'filters' => [],
            'actions_fired' => [],
            'options' => [],
            'transients' => [],
            'doing_it_wrong' => [],
            'caps' => ['manage_options' => true, 'activate_plugins' => true],
            'feed' => null,
            'cron' => [],
            'multisite' => false,
            'sites' => [1],
            'blog' => 1,
            'blog_stack' => [],
            'redirect' => null,
            'tz_offset' => 0,
            'hidden_columns' => [],
            'user_id' => 1,
            'pages' => [],
        ];
    }

    /** @return list<array<int, mixed>> */
    public static function fired(string $hook): array
    {
        return $GLOBALS['wp_stub']['actions_fired'][$hook] ?? [];
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '')
        {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool
{
    $GLOBALS['wp_stub']['filters'][$hook][$priority][] = [$callback, $args];
    return true;
}

function add_action(string $hook, $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_filter(string $hook, $callback, int $priority = 10): bool
{
    foreach ($GLOBALS['wp_stub']['filters'][$hook][$priority] ?? [] as $i => [$cb]) {
        if ($cb === $callback) {
            unset($GLOBALS['wp_stub']['filters'][$hook][$priority][$i]);
            return true;
        }
    }
    return false;
}

function remove_action(string $hook, $callback, int $priority = 10): bool
{
    return remove_filter($hook, $callback, $priority);
}

function has_filter(string $hook, $callback = false)
{
    foreach ($GLOBALS['wp_stub']['filters'][$hook] ?? [] as $priority => $list) {
        foreach ($list as [$cb]) {
            if ($callback === false || $cb === $callback) {
                return $priority;
            }
        }
    }
    return false;
}

function apply_filters(string $hook, $value, ...$args)
{
    $callbacks = $GLOBALS['wp_stub']['filters'][$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $list) {
        foreach ($list as [$cb, $count]) {
            $value = $cb(...array_slice(array_merge([$value], $args), 0, max(1, $count)));
        }
    }
    return $value;
}

function do_action(string $hook, ...$args): void
{
    $GLOBALS['wp_stub']['actions_fired'][$hook][] = $args;
    $callbacks = $GLOBALS['wp_stub']['filters'][$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $list) {
        foreach ($list as [$cb, $count]) {
            $cb(...array_slice($args, 0, $count));
        }
    }
}

function do_action_ref_array(string $hook, array $args): void
{
    do_action($hook, ...$args);
}

function did_action(string $hook): int
{
    return count($GLOBALS['wp_stub']['actions_fired'][$hook] ?? []);
}

function _doing_it_wrong(string $function, string $message, string $version): void
{
    $GLOBALS['wp_stub']['doing_it_wrong'][] = [$function, $message];
}

function get_option(string $name, $default = false)
{
    $blog = $GLOBALS['wp_stub']['blog'];
    return array_key_exists($name, $GLOBALS['wp_stub']['options'][$blog] ?? []) ? $GLOBALS['wp_stub']['options'][$blog][$name] : $default;
}

function update_option(string $name, $value, $autoload = null): bool
{
    $GLOBALS['wp_stub']['options'][$GLOBALS['wp_stub']['blog']][$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['wp_stub']['options'][$GLOBALS['wp_stub']['blog']][$name]);
    return true;
}

function wp_cache_delete($key, string $group = ''): bool
{
    return true;
}

function set_transient(string $key, $value, int $expiration = 0): bool
{
    $GLOBALS['wp_stub']['transients'][$key] = $value;
    return true;
}

function get_transient(string $key)
{
    return $GLOBALS['wp_stub']['transients'][$key] ?? false;
}

function delete_transient(string $key): bool
{
    unset($GLOBALS['wp_stub']['transients'][$key]);
    return true;
}

function esc_html($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_url($url): string
{
    return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
}

function wp_kses_post($text): string
{
    return (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', (string) $text);
}

function wp_strip_all_tags($text): string
{
    $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $text);
    return trim(strip_tags($text));
}

function sanitize_text_field($text): string
{
    return trim(strip_tags((string) $text));
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function wp_http_validate_url($url)
{
    $parts = parse_url((string) $url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return false;
    }
    return $url;
}

function fetch_feed($url)
{
    $feed = $GLOBALS['wp_stub']['feed'];
    if (is_callable($feed)) {
        return $feed($url);
    }
    return new WP_Error('no_feed', 'no feed configured');
}

function current_user_can(string $cap): bool
{
    return !empty($GLOBALS['wp_stub']['caps'][$cap]);
}

function get_current_user_id(): int
{
    return $GLOBALS['wp_stub']['user_id'];
}

function wp_verify_nonce($nonce, $action = -1)
{
    return $nonce === 'valid-nonce' && $action === 'import_scheduler_action' ? 1 : false;
}

function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true): string
{
    return '<input type="hidden" name="' . $name . '" value="valid-nonce">';
}

function wp_die($message = '', $title = '', $args = []): void
{
    throw new WpStubDie((string) $message, (int) ($args['response'] ?? 500));
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . $path;
}

function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function wp_safe_redirect(string $url): bool
{
    $GLOBALS['wp_stub']['redirect'] = $url;
    return true;
}

function wp_date(string $format, ?int $timestamp = null)
{
    return gmdate($format, ($timestamp ?? 0) + $GLOBALS['wp_stub']['tz_offset']);
}

function add_management_page($page_title, $menu_title, $capability, $slug, $callback = '')
{
    $GLOBALS['wp_stub']['pages'][$slug] = $capability;
    return 'tools_page_' . $slug;
}

function get_current_screen()
{
    return (object) ['id' => 'tools_page_import-scheduler'];
}

function get_hidden_columns($screen): array
{
    return $GLOBALS['wp_stub']['hidden_columns'];
}

function is_admin(): bool
{
    return true;
}

function wp_upload_dir($time = null, $create = true): array
{
    return ['basedir' => sys_get_temp_dir()];
}

function wp_next_scheduled(string $hook, array $args = [])
{
    foreach ($GLOBALS['wp_stub']['cron'] as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['time'];
        }
    }
    return false;
}

function wp_schedule_event(int $time, string $recurrence, string $hook, array $args = []): bool
{
    $GLOBALS['wp_stub']['cron'][] = ['time' => $time, 'recurrence' => $recurrence, 'hook' => $hook, 'args' => $args];
    return true;
}

function wp_unschedule_hook(string $hook): int
{
    $before = count($GLOBALS['wp_stub']['cron']);
    $GLOBALS['wp_stub']['cron'] = array_values(array_filter($GLOBALS['wp_stub']['cron'], static fn ($e) => $e['hook'] !== $hook));
    return $before - count($GLOBALS['wp_stub']['cron']);
}

function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    $before = count($GLOBALS['wp_stub']['cron']);
    $GLOBALS['wp_stub']['cron'] = array_values(array_filter($GLOBALS['wp_stub']['cron'], static fn ($e) => !($e['hook'] === $hook && $e['args'] === $args)));
    return $before - count($GLOBALS['wp_stub']['cron']);
}

function is_multisite(): bool
{
    return $GLOBALS['wp_stub']['multisite'];
}

function get_sites(array $args = []): array
{
    return $GLOBALS['wp_stub']['sites'];
}

function switch_to_blog(int $id): bool
{
    $GLOBALS['wp_stub']['blog_stack'][] = $GLOBALS['wp_stub']['blog'];
    $GLOBALS['wp_stub']['blog'] = $id;
    return true;
}

function restore_current_blog(): bool
{
    $GLOBALS['wp_stub']['blog'] = array_pop($GLOBALS['wp_stub']['blog_stack']) ?? 1;
    return true;
}

WpStub::reset();
