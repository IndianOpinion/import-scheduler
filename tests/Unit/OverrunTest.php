<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\RunContext;
use IndianOpinion\ImportScheduler\RunResult;
use IndianOpinion\ImportScheduler\RuleState;
use IndianOpinion\ImportScheduler\Tests\Support\MemoryStorage;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class OverrunTest extends TestCase
{
    /** T30 (4.7.2, 4.15.4) */
    public function testARuleWhoseLockIsHeldIsSkippedByATickAndRefusedByRun(): void
    {
        $this->rule('r');
        $this->plugin->operator()->rules(true);
        $before = $this->state('r')['next_run'];
        $holder = Locks::newOwner();
        $this->storage->insertLock('rule:r', $holder, 600);
        [, $lines] = $this->tick();
        $this->assertSame([], $this->runs('r'));
        $this->assertSame($before, $this->state('r')['next_run'], 'next run unchanged');
        $this->assertStringContainsString('skipped: rule lock held', implode("\n", $lines));

        [$cli, $io] = $this->cli();
        $cli->run(['r'], []);
        $this->assertSame(1, $io->code());
        $this->assertStringContainsString('locked by ' . substr($holder, 0, 8), $io->text());
        $this->assertSame([], $this->runs('r'));
    }

    /** T31 (4.7.3) */
    public function testALongRunInSlotOneDoesNotStopSlotTwo(): void
    {
        $this->settings(['lane_concurrency' => ['default' => 2]]);
        $second = $this->makePlugin(new MemoryStorage($this->db), $this->registry);
        $inner = null;
        $this->registry->register('a-long', function () use (&$inner, $second): void {
            $inner = $this->tick('default', null, false, false, $second);
            $this->clock->advance(600);
        }, ['interval' => 3600, 'max_runtime' => 900]);
        $this->rule('b-short', 1);
        $this->tick('default', 1);
        $this->assertNotNull($inner);
        $this->assertSame(0, $inner[0]);
        $this->assertCount(1, $this->runs('a-long'), 'slot 2 skipped the rule slot 1 was running');
        $this->assertCount(1, $this->runs('b-short'), 'and ran the other rule');
        $this->assertSame([2, 1], array_map('intval', array_column($this->db->ticks, 'slot')));
    }

    /** T32 (4.7.4, 4.8.8) */
    public function testShouldStopAtTheDeadlineOnAStopRequestAndOnTheKillSwitch(): void
    {
        $seen = [];
        $this->registry->register('deadline', function (RunContext $c) use (&$seen): void {
            $seen['before'] = $c->shouldStop();
            $this->clock->advance(61);
            $seen['after'] = $c->shouldStop();
        }, ['interval' => 300, 'max_runtime' => 60]);
        $this->registry->register('asked', function (RunContext $c) use (&$seen): void {
            $seen['asked-before'] = $c->shouldStop();
            [$cli, $io] = $this->cli();
            $cli->stop(['asked'], []);
            $seen['asked-after'] = $c->shouldStop();
            $seen['asked-io'] = $io->text();
        }, ['interval' => 300]);
        $this->registry->register('killed', function (RunContext $c) use (&$seen): void {
            $seen['killed-before'] = $c->shouldStop();
            $this->settings(['enabled' => false]);
            $seen['killed-after'] = $c->shouldStop();
            $this->settings(['enabled' => true]);
        }, ['interval' => 300]);
        $this->registry->register('lane-paused', function (RunContext $c) use (&$seen): void {
            $this->plugin->operator()->setLanePaused('default', true);
            $seen['lane'] = $c->shouldStop();
        }, ['interval' => 300]);
        $this->tick('default', 3600);
        $this->assertFalse($seen['before']);
        $this->assertTrue($seen['after']);
        $this->assertFalse($seen['asked-before']);
        $this->assertTrue($seen['asked-after']);
        $this->assertStringContainsString('stop requested', $seen['asked-io']);
        $this->assertSame(0, $this->state('asked')['stop_requested'], 'cleared when the run ends');
        $this->assertFalse($seen['killed-before']);
        $this->assertTrue($seen['killed-after']);
        $this->assertTrue($seen['lane']);

        [$cli, $io] = $this->cli();
        $cli->stop(['asked'], []);
        $this->assertStringContainsString('not running', $io->text());

        $this->constantDisabled = true;
        $this->plugin = $this->makePlugin($this->storage, $this->registry);
        $this->assertSame([0, ['disabled']], $this->tick());
    }

    /** T33 (4.7.5) */
    public function testARunWhoseLockWasTakenOverRecordsLockLost(): void
    {
        $intruder = Locks::newOwner();
        $this->registry->register('r', function () use ($intruder) {
            $this->storage->deleteLock('rule:r');
            $this->storage->insertLock('rule:r', $intruder, 600);
            return RunResult::success(3);
        }, ['interval' => 300]);
        $this->plugin->operator()->rules(true);
        $nextBefore = $this->state('r')['next_run'];
        $this->tick();
        $run = $this->runs('r')[0];
        $this->assertSame('lock_lost', $run['status']);
        $this->assertSame('success', $run['original_status']);
        $this->assertSame(3, $run['items']);
        $this->assertSame($nextBefore, $this->state('r')['next_run'], 'the newer run owns next run');
        $this->assertSame($intruder, $this->db->locks['rule:r']['owner'], 'the new holder keeps its lock');
        $this->assertTrue($this->plugin->operator()->rule('r')->lockLost(), 'the screen warns');
        $this->assertNotEmpty(array_filter($this->logged, static fn (string $l): bool => str_contains($l, 'lost its lock')));
    }

    /** T34 (4.7.6) */
    public function testThreeOverrunsMarkARuleConsistentlySlow(): void
    {
        $this->rule('slow', 30, null, ['interval' => 300, 'max_runtime' => 10]);
        for ($i = 0; $i < 3; $i++) {
            $this->plugin->operator()->markDue('slow');
            $this->tick();
        }
        $state = $this->plugin->operator()->rule('slow');
        $this->assertSame(3, $state->consecutiveOverruns);
        $this->assertSame(RuleState::STATUS_SLOW, $state->status($this->db->now()));
        $html = (new \IndianOpinion\ImportScheduler\Admin\Screen($this->plugin))->render([]);
        $this->assertStringContainsString('consistently slow: raise its max runtime or give it a lane of its own', $html);
        $this->assertSame('default', $this->state('slow')['lane'], 'never moved automatically');
    }

    /** T35 (4.7.7) */
    public function testARuleSlowerThanItsIntervalRunsBackToBackWithRest(): void
    {
        $second = $this->makePlugin(new MemoryStorage($this->db), $this->registry);
        $busy = [];
        $this->registry->register('slow', function () use (&$busy, $second): void {
            for ($i = 0; $i < 3; $i++) {
                $this->clock->advance(30);
                $busy[] = $this->tick('default', null, false, false, $second);
            }
        }, ['interval' => 60, 'max_runtime' => 60]);
        $this->rule('waiting', 0, null, ['interval' => 60]);
        $this->tick('default', 5);
        foreach ($busy as [$code, $lines]) {
            $this->assertSame([0, ['lane busy']], [$code, $lines]);
        }
        $run = $this->runs('slow')[0];
        $this->assertSame('overrun', $run['status']);
        $this->assertSame((int) $run['finished'] + 60, $this->state('slow')['next_run']);
        $this->assertSame([], $this->runs('waiting'), 'the other rules on the lane wait');
    }

    /** T37 (4.8.2, 4.3.4) */
    public function testARunningRowWithAnExpiredLockBecomesAbandoned(): void
    {
        $this->rule('r', 0, null, ['interval' => 300, 'max_runtime' => 60]);
        $this->plugin->operator()->rules(true);
        $now = $this->db->now();
        $owner = Locks::newOwner();
        $runId = $this->storage->insertRun(['rule_id' => 'r', 'lane' => 'default', 'started' => $now, 'status' => 'running', 'owner' => $owner]);
        $this->storage->updateRuleState('r', ['running_run_id' => $runId, 'last_start' => $now, 'next_run' => $now + 10000]);
        $this->storage->insertLock('rule:r', $owner, 120);

        $this->tick();
        $this->assertSame('running', $this->db->runs[$runId]['status'], 'a live lock means a live run');

        $this->clock->advance(200);
        $this->tick();
        $this->assertSame('abandoned', $this->db->runs[$runId]['status']);
        $this->assertSame($now + 120, $this->db->runs[$runId]['finished'], 'finish time is the lock expiry');
        $state = $this->state('r');
        $this->assertSame(1, $state['consecutive_failures']);
        $this->assertNull($state['running_run_id']);
        $this->assertSame('abandoned', $state['last_status']);
        $this->assertSame($this->db->now() + 300, $state['next_run'], 'next run as for a failure');
    }

    /** T61 (4.15.5, 4.15.9) */
    public function testALaneMoveDuringARunTakesEffectOnTheNextRun(): void
    {
        $this->registry->register('mover', function () {
            $this->plugin->operator()->moveLane('mover', 'slow');
            $this->registry->register('newcomer', static fn () => null, ['interval' => 300]);
            return 1;
        }, ['interval' => 300]);
        $this->rule('z-last');
        $this->tick();
        $this->assertSame('default', $this->runs('mover')[0]['lane'], 'finished under its old lane');
        $this->assertSame('slow', $this->state('mover')['lane']);
        $this->assertSame('slow', $this->state('mover')['lane_override']);
        $this->assertSame([], $this->runs('newcomer'), 'registered mid-tick: applies from the next tick');

        $this->plugin->operator()->markDue('mover');
        $this->tick();
        $this->assertCount(1, $this->runs('mover'), 'not on the old lane any more');
        $this->assertCount(1, $this->runs('newcomer'));
        $this->tick('slow');
        $this->assertCount(2, $this->runs('mover'));
        $this->assertSame('slow', $this->runs('mover')[1]['lane']);
    }
}
