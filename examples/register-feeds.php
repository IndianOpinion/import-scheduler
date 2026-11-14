<?php

/**
 * Plugin Name: Import Scheduler example: feeds to drafts
 * Description: Registers two feeds and turns each new item into a draft post. A starting point, not a finished importer.
 *
 * Drop this file into wp-content/plugins/ (or mu-plugins/) next to Import
 * Scheduler. Register rules unconditionally: a rule registered only in
 * wp-admin does not exist in the WP-CLI process that runs the lane.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('import_scheduler_register', static function (): void {
    // A busy feed every 5 minutes on the default lane.
    import_scheduler_register_feed('example-news', 'https://example.com/news/feed/', [
        'label' => 'Example news',
        'interval' => 300,
        'max_items' => 30,
        'since_last_success' => true,
    ]);

    // A slow source on a lane of its own, so it never holds up the rest.
    import_scheduler_register_feed('example-slow', 'https://example.org/slow/feed/', [
        'interval' => 3600,
        'lane' => 'slow',
        'timeout' => 30,
        'max_runtime' => 600,
        'start_delay' => 120,
    ]);
});

/**
 * Turn each item into a draft, once. The item key is stable across runs,
 * so it is the de-duplication key.
 *
 * @param array{url: string, title: string, body: string, source_name: string, published: ?int, key: string, feed_url: string, rule_id: string} $item
 */
add_action('import_scheduler_item', static function (array $item, string $ruleId): void {
    $hash = md5($item['key']);
    $existing = get_posts([
        'post_type' => 'post',
        'post_status' => 'any',
        'meta_key' => '_example_import_key',
        'meta_value' => $hash,
        'fields' => 'ids',
        'posts_per_page' => 1,
        'no_found_rows' => true,
    ]);
    if ($existing !== []) {
        return;
    }
    $postId = wp_insert_post([
        'post_title' => $item['title'],
        'post_content' => $item['body'],
        'post_status' => 'draft',
        'post_date_gmt' => $item['published'] !== null ? gmdate('Y-m-d H:i:s', $item['published']) : '',
        'meta_input' => [
            '_example_import_key' => $hash,
            '_example_source_url' => esc_url_raw($item['url']),
            '_example_source_name' => $item['source_name'],
        ],
    ], true);
    if (is_wp_error($postId)) {
        // Throwing fails the run, so the failure shows on the screen.
        throw new RuntimeException('could not create a draft: ' . $postId->get_error_message());
    }
}, 10, 2);
