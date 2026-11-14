<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Admin\Screen;
use IndianOpinion\ImportScheduler\Registry;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class FailureModesTest extends TestCase
{
    /** T39 (4.8.4, 4.8.6, 4.8.5) */
    public function testDatabaseDownMissingTablesAndAFailedWriteAfterARun(): void
    {
        $ran = 0;
        $this->registry->register('r', static function () use (&$ran): int {
            $ran++;
            return 1;
        }, ['interval' => 300]);

        $this->db->down = true;
        [$code, $lines] = $this->tick();
        $this->assertSame(1, $code);
        $this->assertSame(0, $ran, 'never runs a rule it cannot record');
        $this->assertStringContainsString('Database unreachable', $lines[0]);
        $this->db->down = false;

        $this->db->tablesMissing = true;
        [$code] = $this->tick();
        $this->assertSame(0, $code);
        $this->assertSame(1, $this->db->installs, 'created once');
        $this->assertSame(1, $ran);

        $this->db->tablesMissing = true;
        $this->db->installFails = true;
        $this->plugin->operator();
        [$code, $lines] = $this->tick();
        $this->assertSame(1, $code);
        $this->assertSame(2, $this->db->installs);
        $this->assertStringContainsString('tables are missing and could not be created', $lines[0]);
        $this->db->tablesMissing = false;
        $this->db->installFails = false;

        // The database goes away while a rule runs: logged with the rule id, lock left to expire.
        $this->registry->register('cut', function (): int {
            $this->db->down = true;
            return 4;
        }, ['interval' => 300]);
        [$code] = $this->tick();
        $this->assertSame(1, $code);
        $this->db->down = false;
        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('rule cut: could not record the run', $log);
        $this->assertArrayHasKey('rule:cut', $this->db->locks, 'cleanup is left to the lock expiry');
    }

    /** T40 (4.8.9) */
    public function testARuleRegisteredOnlyInWpAdminIsARegistrationProblem(): void
    {
        // wp-admin registers two rules; WP-CLI registers only one.
        $this->rule('everywhere');
        $this->rule('admin-only');
        $this->plugin->operator()->rules(true);
        $cliRegistry = new Registry();
        $cliRegistry->open();
        $cliRegistry->register('everywhere', static fn () => null, ['interval' => 300]);
        $this->tick('default', null, false, false, $this->makePlugin($this->storage, $cliRegistry));
        $this->assertSame([], $this->runs('admin-only'));

        $html = (new Screen($this->plugin))->render([]);
        $this->assertStringContainsString('Registration problems', $html);
        $this->assertStringContainsString('admin-only is registered in wp-admin but the last tick of its lane did not see it', $html);
        $this->assertStringNotContainsString('everywhere is registered in wp-admin', $html);
        [$cli, $io] = $this->cli();
        $cli->status([], []);
        $this->assertStringContainsString('Rule admin-only is registered here but the last tick did not see it', $io->text());
    }
}
