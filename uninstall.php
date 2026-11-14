<?php

/**
 * Uninstall: drop the plugin's tables and delete its options, on every site
 * of a network. The system crontab line cannot be removed from here; remove
 * it yourself.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!class_exists(\IndianOpinion\ImportScheduler\Installer::class)) {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\ImportScheduler\Installer::uninstall(static function (): \IndianOpinion\ImportScheduler\Installer {
    global $wpdb;
    return \IndianOpinion\ImportScheduler\Installer::forWordPress(new \IndianOpinion\ImportScheduler\Storage\WpdbConnection($wpdb));
});
