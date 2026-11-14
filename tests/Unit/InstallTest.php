<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Fallback;
use IndianOpinion\ImportScheduler\Installer;
use IndianOpinion\ImportScheduler\Plugin;
use IndianOpinion\ImportScheduler\Registry;
use IndianOpinion\ImportScheduler\Settings;
use IndianOpinion\ImportScheduler\Storage\Schema;
use IndianOpinion\ImportScheduler\Storage\SqlStorage;
use IndianOpinion\ImportScheduler\Tests\Support\RecordingConnection;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class InstallTest extends TestCase
{
    /**
     * Columns as SHOW COLUMNS would list them, minus some.
     *
     * @param list<string> $drop
     */
    private function connectionWithColumns(array $drop = [], string $prefix = 'wp_'): RecordingConnection
    {
        $db = new RecordingConnection($prefix);
        foreach (Schema::COLUMNS as $table => $columns) {
            $rows = [];
            foreach (array_diff($columns, $drop) as $column) {
                $rows[] = ['Field' => $column, 'Type' => 'x'];
            }
            $db->answers['/^SHOW COLUMNS FROM ' . preg_quote($prefix . $table, '/') . '$/'] = $rows;
        }
        return $db;
    }

    /** T50 (4.13.2, 4.13.1) */
    public function testAMissingColumnIsDetectedAndReported(): void
    {
        $applied = [];
        $db = $this->connectionWithColumns(['tick_orphan']);
        $installer = new Installer($db, 'DEFAULT CHARSET=utf8mb4', static function (array $statements) use (&$applied): void {
            $applied = $statements;
        });
        $this->assertFalse($installer->install());
        $this->assertCount(4, $applied, 'four tables');
        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $applied[0]);
        $this->assertStringContainsString('PRIMARY KEY  (rule_id)', $applied[0]);
        $this->assertSame([Schema::RULES => ['tick_orphan']], Installer::missingColumns());
        $this->assertSame(Schema::VERSION, get_option(Schema::VERSION_OPTION));
        $this->assertNotEmpty(array_filter($this->logged, static fn (string $l): bool => str_contains($l, 'missing columns')));

        $html = (new \IndianOpinion\ImportScheduler\Admin\Screen($this->plugin))->render([]);
        $this->assertStringContainsString('Missing database columns: import_scheduler_rules (tick_orphan)', $html);
        [$cli, $io] = $this->cli();
        $cli->status([], []);
        $this->assertStringContainsString('Missing columns: import_scheduler_rules (tick_orphan)', $io->text());

        $good = new Installer($this->connectionWithColumns(), '', static fn (array $s) => null);
        $this->assertTrue($good->install());
        $this->assertSame([], Installer::missingColumns());

        // Without a table helper, plain CREATE TABLE IF NOT EXISTS.
        $plain = $this->connectionWithColumns();
        (new Installer($plain))->install();
        $this->assertCount(4, array_filter($plain->sql(), static fn (string $s): bool => str_starts_with($s, 'CREATE TABLE IF NOT EXISTS wp_import_scheduler_')));

        // Upgrades run only when the stored version differs.
        update_option(Schema::VERSION_OPTION, '0');
        $count = 0;
        (new Installer($this->connectionWithColumns(), '', static function () use (&$count): void {
            $count++;
        }))->maybeUpgrade();
        (new Installer($this->connectionWithColumns(), '', static function () use (&$count): void {
            $count++;
        }))->maybeUpgrade();
        $this->assertSame(1, $count);
    }

    /** T51 (4.13.3, 4.13.4) */
    public function testDeactivationRemovesFallbackEventsOnlyAndUninstallDropsEverythingPerSite(): void
    {
        update_option(Settings::OPTION, ['tick_budget' => 30]);
        update_option(Schema::VERSION_OPTION, Schema::VERSION);
        wp_schedule_event(1, Fallback::SCHEDULE, Fallback::HOOK, ['default']);
        wp_schedule_event(1, Fallback::SCHEDULE, Fallback::HOOK, ['slow']);
        wp_schedule_event(1, 'hourly', 'someone_elses_event');
        Installer::deactivate();
        $this->assertSame(['someone_elses_event'], array_column($GLOBALS['wp_stub']['cron'], 'hook'));
        $this->assertSame(['tick_budget' => 30], get_option(Settings::OPTION), 'settings kept');
        $this->assertSame(Schema::VERSION, get_option(Schema::VERSION_OPTION));

        $GLOBALS['wp_stub']['multisite'] = true;
        $GLOBALS['wp_stub']['sites'] = [1, 2];
        switch_to_blog(2);
        update_option(Settings::OPTION, ['tick_budget' => 20]);
        restore_current_blog();
        $connections = [];
        Installer::uninstall(static function () use (&$connections): Installer {
            $blog = $GLOBALS['wp_stub']['blog'];
            $connections[$blog] = new RecordingConnection($blog === 1 ? 'wp_' : 'wp_' . $blog . '_');
            return new Installer($connections[$blog]);
        });
        $this->assertSame([1, 2], array_keys($connections));
        $this->assertSame([
            'DROP TABLE IF EXISTS wp_2_import_scheduler_rules',
            'DROP TABLE IF EXISTS wp_2_import_scheduler_runs',
            'DROP TABLE IF EXISTS wp_2_import_scheduler_locks',
            'DROP TABLE IF EXISTS wp_2_import_scheduler_ticks',
        ], $connections[2]->sql());
        $this->assertCount(4, $connections[1]->sql());
        $this->assertFalse(get_option(Settings::OPTION));
        $this->assertFalse(get_option(Schema::VERSION_OPTION));
        switch_to_blog(2);
        $this->assertFalse(get_option(Settings::OPTION));
        restore_current_blog();
        $this->assertSame(1, $GLOBALS['wp_stub']['blog'], 'back on the original site');
    }

    /** T67 (4.13.5) */
    public function testATickForOneSiteTouchesOnlyThatSitesTables(): void
    {
        $db = new RecordingConnection('wp_2_');
        $db->answers['/^SELECT \* FROM wp_2_import_scheduler_rules WHERE lane = %s/'] = [];
        $registry = new Registry();
        $registry->open();
        $registry->register('feed', static fn () => 1, ['interval' => 300]);
        $plugin = new Plugin(new SqlStorage($db), $registry, fn (bool $f = false): Settings => new Settings(), $this->clock);
        [$code] = $this->tick('default', null, false, false, $plugin);
        $this->assertSame(0, $code);
        $tables = [];
        foreach ($db->sql() as $sql) {
            if (preg_match_all('/\b(wp_\w+)/', $sql, $m) > 0) {
                foreach ($m[1] as $table) {
                    $tables[$table] = true;
                }
            }
        }
        $this->assertNotEmpty($tables);
        foreach (array_keys($tables) as $table) {
            $this->assertStringStartsWith('wp_2_import_scheduler_', $table);
        }
    }
}
