<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Registry;
use IndianOpinion\ImportScheduler\RunResult;
use IndianOpinion\ImportScheduler\Storage\Schema;
use IndianOpinion\ImportScheduler\Storage\SqlStorage;
use IndianOpinion\ImportScheduler\Tests\Support\MemoryStorage;
use IndianOpinion\ImportScheduler\Tests\Support\RecordingConnection;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class TickTest extends TestCase
{
    /** T9 (4.3.1, 4.3.6, 4.3.9, 4.3.10) */
    public function testBudgetStopsChoosingAndTheSlotIsRenewedAndReleased(): void
    {
        $beats = [];
        foreach (['a', 'b', 'c'] as $id) {
            $this->registry->register($id, function () use (&$beats): void {
                $beats[] = $this->db->locks['lane:default:1']['heartbeat'] ?? null;
                $this->clock->advance(3);
            }, ['interval' => 300]);
        }
        [$code, $lines] = $this->tick('default', 5);
        $this->assertSame(0, $code);
        $this->assertCount(1, $this->runs('a'));
        $this->assertCount(1, $this->runs('b'));
        $this->assertSame([], $this->runs('c'));
        $this->assertLessThanOrEqual($this->db->now(), $this->state('c')['next_run'], 'c stays due');
        $this->assertGreaterThan($beats[0], $beats[1], 'slot heartbeat renewed after a run');
        $this->assertArrayNotHasKey('lane:default:1', $this->db->locks, 'slot released');
        $this->assertStringContainsString('stopped: budget spent', end($lines));
    }

    /** T10 (4.3.2, 4.11.1 to 4.11.4) */
    public function testEachGuardStandsDownWithAReasonAndNoLock(): void
    {
        $this->rule('r');
        $cases = [];
        $this->diskFree = static fn (string $d): float => 10 * 1048576.0;
        $this->settings(['min_free_disk_mb' => 100]);
        $cases['low disk'] = $this->tick();
        $this->settings(['min_free_disk_mb' => 0, 'min_available_memory_mb' => 512]);
        $meminfo = tempnam(sys_get_temp_dir(), 'mem');
        file_put_contents($meminfo, "MemTotal:  2048000 kB\nMemAvailable:   102400 kB\n");
        $this->guardMeminfo = $meminfo;
        $this->plugin = $this->makePlugin($this->storage, $this->registry);
        $cases['low memory'] = $this->tick();
        $this->settings(['min_available_memory_mb' => 0]);
        add_filter('import_scheduler_should_tick', static fn ($ok, $lane) => false, 10, 2);
        $cases['stood down by a filter'] = $this->tick();
        remove_filter('import_scheduler_should_tick', $GLOBALS['wp_stub']['filters']['import_scheduler_should_tick'][10][0][0]);
        add_filter('import_scheduler_should_tick', static fn ($ok, $lane) => 'maintenance window on ' . $lane, 10, 2);
        $cases['maintenance window on default'] = $this->tick();
        unlink($meminfo);

        foreach ($cases as $reason => [$code, $lines]) {
            $this->assertSame(0, $code);
            $this->assertStringContainsString('stood down: ' . $reason, $lines[0]);
        }
        $this->assertSame([], $this->db->locks);
        $this->assertSame([], $this->runs('r'));
        $reasons = array_map(static fn (array $t): string => (string) $t['reason'], array_values($this->db->ticks));
        $this->assertCount(4, $reasons);
        $this->assertStringContainsString('low disk', $reasons[0]);
        $this->assertSame('stood_down', $this->db->ticks[1]['outcome']);

        // Self-healing, and an unreadable meminfo passes.
        $GLOBALS['wp_stub']['filters']['import_scheduler_should_tick'] = [];
        $this->settings(['min_available_memory_mb' => 512]);
        $this->guardMeminfo = '/nonexistent/meminfo';
        $this->plugin = $this->makePlugin($this->storage, $this->registry);
        [$code] = $this->tick();
        $this->assertSame(0, $code);
        $this->assertCount(1, $this->runs('r'));
        $guards = $this->plugin->guards($this->plugin->settings());
        $this->assertNull($guards->check('default'));
        $this->assertStringContainsString('not readable', $guards->notes()[0]);
    }

    /** T11 (4.3.3, 4.15.3) */
    public function testTwoConcurrentTicksOnAOneSlotLane(): void
    {
        $other = null;
        $second = $this->makePlugin(new MemoryStorage($this->db), $this->registry);
        $this->registry->register('slow', function () use (&$other, $second): void {
            $other = $this->tick('default', null, false, false, $second);
        }, ['interval' => 300]);
        [$code] = $this->tick();
        $this->assertSame(0, $code);
        $this->assertNotNull($other);
        $this->assertSame(0, $other[0]);
        $this->assertSame(['lane busy'], $other[1]);
        $this->assertCount(1, $this->runs('slow'));
        $outcomes = array_column($this->db->ticks, 'outcome');
        $this->assertSame(['busy', 'ran'], $outcomes, 'the busy attempt is recorded against the lane');
    }

    /** T12 (4.3.5, 4.3.11) */
    public function testDueRulesRunOldestFirstAndLeftoversLeadTheNextTick(): void
    {
        $order = [];
        $now = $this->db->now();
        foreach (['b' => 100, 'a' => 100, 'c' => 50, 'd' => 10] as $id => $age) {
            $this->registry->register($id, function () use (&$order, $id): void {
                $order[] = $id;
                $this->clock->advance(4);
            }, ['interval' => 3600]);
        }
        $this->plugin->operator()->rules(true);
        foreach (['b' => 100, 'a' => 100, 'c' => 50, 'd' => 10] as $id => $age) {
            $this->storage->updateRuleState($id, ['next_run' => $now - $age]);
        }
        $this->tick('default', 5);
        $this->assertSame(['a', 'b'], $order, 'oldest due first, ties by id');
        $this->registry->register('e', function () use (&$order): void {
            $order[] = 'e';
        }, ['interval' => 3600]);
        $this->tick('default', 5);
        $this->assertSame(['a', 'b', 'c', 'd'], $order, 'leftovers are now the oldest due');
    }

    /** T13 (4.3.7, 4.15.2) */
    public function testARuleRunsAtMostOncePerTick(): void
    {
        $this->rule('quick', 70, null, ['interval' => 60]);
        $this->rule('slow', 70, null, ['interval' => 3600]);
        $this->tick('default', 3600);
        $this->assertCount(1, $this->runs('quick'));
        $this->assertCount(1, $this->runs('slow'));

        $this->settings(['expected_cadence' => 300]);
        $html = (new \IndianOpinion\ImportScheduler\Admin\Screen($this->plugin))->render([]);
        $this->assertStringContainsString('effective cadence limited by cron', $html);
    }

    /** T14 (4.3.8) */
    public function testMemoryAboveTheCeilingStopsSelection(): void
    {
        $this->memoryLimit = '100M';
        $this->registry->register('hungry', function (): void {
            $this->memoryUsage = 90 * 1048576;
        }, ['interval' => 300]);
        $this->rule('next');
        [, $lines] = $this->tick();
        $this->assertCount(1, $this->runs('hungry'));
        $this->assertSame([], $this->runs('next'));
        $this->assertStringContainsString('memory ceiling', end($lines));

        $this->memoryLimit = '-1';
        $this->tick();
        $this->assertCount(1, $this->runs('next'), 'no limit, no ceiling');
    }

    /** T15 (4.3.12) */
    public function testExitCodes(): void
    {
        $this->assertSame(0, $this->tick()[0], 'no rules');
        $this->rule('ok');
        $this->assertSame(0, $this->tick()[0], 'ran');
        $this->assertSame(0, $this->tick()[0], 'nothing due');
        $this->db->locks['lane:default:1'] = ['name' => 'lane:default:1', 'owner' => str_repeat('a', 32), 'acquired' => 0, 'expires' => $this->db->now() + 600, 'heartbeat' => 0];
        $this->plugin->operator()->markDue('ok');
        $this->assertSame([0, ['lane busy']], $this->tick());
        unset($this->db->locks['lane:default:1']);
        add_filter('import_scheduler_should_tick', static fn () => false);
        $this->assertSame(0, $this->tick()[0], 'stood down');
        $GLOBALS['wp_stub']['filters'] = [];
        $this->db->down = true;
        [$code, $lines] = $this->tick();
        $this->assertSame(1, $code, 'database down');
        $this->assertStringContainsString('Database unreachable', $lines[0]);
        $this->db->down = false;
        $this->rule('bad', 0, false);
        $this->assertSame(0, $this->tick()[0], 'a failure without --strict');
        $this->plugin->operator()->markDue('bad');
        $this->assertSame(1, $this->tick('default', null, false, true)[0], 'a failure with --strict');
    }

    /** T16 (4.3.13) */
    public function testDryRunListsTheOrderAndTakesNoLock(): void
    {
        $now = $this->db->now();
        $this->rule('second');
        $this->rule('first');
        $this->rule('paused');
        $this->rule('future');
        $this->plugin->operator()->rules(true);
        $this->storage->updateRuleState('second', ['next_run' => $now - 10]);
        $this->storage->updateRuleState('first', ['next_run' => $now - 20]);
        $this->plugin->operator()->pause('paused', 'checking');
        $this->storage->updateRuleState('future', ['next_run' => $now + 500]);
        $locksBefore = $this->db->locks;
        $runsBefore = $this->db->runs;
        [$code, $lines] = $this->tick('default', null, true);
        $this->assertSame(0, $code);
        $text = implode("\n", $lines);
        $this->assertLessThan(strpos($text, 'would run 2: second'), strpos($text, 'would run 1: first'));
        $this->assertStringContainsString('paused: paused (checking)', $text);
        $this->assertStringContainsString('future: not due until', $text);
        $this->assertSame($locksBefore, $this->db->locks);
        $this->assertSame($runsBefore, $this->db->runs);
        $this->assertSame([], $this->db->ticks);
    }

    /** T60 (4.15.1) */
    public function testZeroRulesTakesNoLock(): void
    {
        [$code, $lines] = $this->tick();
        $this->assertSame([0, ['no rules']], [$code, $lines]);
        $this->assertSame([], $this->db->locks);
    }

    /** T62 (4.15.7, section 9) */
    public function testDueSelectionIsCappedAndIndexed(): void
    {
        $registry = new Registry();
        $registry->open();
        for ($i = 0; $i < 1000; $i++) {
            $registry->register(sprintf('r%04d', $i), static fn () => RunResult::success(), ['interval' => 300]);
        }
        $plugin = $this->makePlugin($this->storage, $registry);
        $plugin->operator()->rules(true);
        $this->assertCount(200, $this->storage->dueRuleStates('default', $this->db->now(), 200));

        $db = new RecordingConnection();
        (new SqlStorage($db))->dueRuleStates('default', 100, 200);
        $sql = $db->sql()[0];
        $this->assertMatchesRegularExpression('/WHERE lane = %s AND paused = 0 AND tick_orphan = 0 AND next_run <= %d ORDER BY next_run ASC, rule_id ASC LIMIT %d/', $sql);
        $this->assertSame(['default', 100, 200], $db->statements[0][1]);
        $create = (new Schema($db))->statements()[Schema::RULES];
        $this->assertStringContainsString('KEY due (lane,paused,tick_orphan,next_run)', $create);

        // Nothing due costs a handful of statements.
        $this->db = new \IndianOpinion\ImportScheduler\Tests\Support\MemoryDatabase($this->clock);
        $this->storage = new MemoryStorage($this->db);
        $quiet = new Registry();
        $quiet->open();
        $quiet->register('one', static fn () => null, ['interval' => 3600]);
        $p = $this->makePlugin($this->storage, $quiet);
        $this->tick('default', null, false, false, $p);
        $this->clock->advance(1000); // outside the hourly prune window
        $before = $this->db->queries;
        $this->tick('default', null, false, false, $p);
        $this->assertLessThanOrEqual(6, $this->db->queries - $before);
    }
}
