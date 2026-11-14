<?php

/**
 * Plugin Name:       Import Scheduler
 * Plugin URI:        https://github.com/IndianOpinion/import-scheduler
 * Description:       Runs each import rule on its own interval on a dedicated cron lane with its own database lock, so one slow pass never starves the rest.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       import-scheduler
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(\IndianOpinion\ImportScheduler\Plugin::class)) {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\ImportScheduler\Plugin::instance()->boot(__FILE__);
