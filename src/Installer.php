<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

use IndianOpinion\ImportScheduler\Storage\Connection;
use IndianOpinion\ImportScheduler\Storage\Schema;

/**
 * Activation, upgrade, column checks, deactivation and uninstall.
 */
final class Installer
{
    public const MISSING_OPTION = 'import_scheduler_missing_columns';

    /** @var (callable(list<string>): mixed)|null */
    private $apply;

    /**
     * @param (callable(list<string>): mixed)|null $apply Table helper (dbDelta in WordPress).
     */
    public function __construct(private Connection $db, private string $charsetCollate = '', ?callable $apply = null)
    {
        $this->apply = $apply;
    }

    public static function forWordPress(Connection $db): self
    {
        global $wpdb;
        $collate = ($wpdb instanceof \wpdb) ? $wpdb->get_charset_collate() : '';
        return new self($db, $collate, static function (array $statements): void {
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }
            dbDelta($statements);
        });
    }

    public function schema(): Schema
    {
        return new Schema($this->db, $this->charsetCollate);
    }

    /**
     * Create or upgrade the tables, verify every column, store the result.
     *
     * @return bool True when every expected column exists.
     */
    public function install(): bool
    {
        $missing = $this->schema()->install($this->apply);
        update_option(Schema::VERSION_OPTION, Schema::VERSION, true);
        if (get_option(Settings::OPTION) === false) {
            update_option(Settings::OPTION, Settings::DEFAULTS, false);
        }
        if ($missing === []) {
            delete_option(self::MISSING_OPTION);
            return true;
        }
        update_option(self::MISSING_OPTION, $missing, false);
        Log::error('schema check found missing columns: ' . self::describe($missing));
        return false;
    }

    /**
     * Run the install again when the stored schema version differs.
     */
    public function maybeUpgrade(): void
    {
        if (get_option(Schema::VERSION_OPTION) !== Schema::VERSION) {
            $this->install();
        }
    }

    /**
     * Missing columns found by the last install or check.
     *
     * @return array<string, list<string>>
     */
    public static function missingColumns(): array
    {
        $missing = get_option(self::MISSING_OPTION, []);
        /** @var array<string, list<string>> */
        return is_array($missing) ? $missing : [];
    }

    /**
     * @param array<string, list<string>> $missing
     */
    public static function describe(array $missing): string
    {
        $parts = [];
        foreach ($missing as $table => $columns) {
            $parts[] = $table . ' (' . implode(', ', $columns) . ')';
        }
        return implode('; ', $parts);
    }

    /**
     * Deactivation removes fallback events only. Tables, settings and the
     * crontab line stay.
     */
    public static function deactivate(): void
    {
        wp_unschedule_hook(Fallback::HOOK);
    }

    /**
     * Drop tables and delete options for this site.
     */
    public function uninstallSite(): void
    {
        self::deactivate();
        $this->schema()->drop();
        foreach ([Settings::OPTION, Schema::VERSION_OPTION, self::MISSING_OPTION] as $option) {
            delete_option($option);
        }
    }

    /**
     * Uninstall on every site of a network, or on the one site.
     *
     * @param callable(): Installer $forCurrentSite Builds an installer for the current blog.
     */
    public static function uninstall(callable $forCurrentSite): void
    {
        if (function_exists('is_multisite') && is_multisite()) {
            $ids = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach (is_array($ids) ? $ids : [] as $id) {
                switch_to_blog((int) $id);
                try {
                    $forCurrentSite()->uninstallSite();
                } finally {
                    restore_current_blog();
                }
            }
            return;
        }
        $forCurrentSite()->uninstallSite();
    }
}
