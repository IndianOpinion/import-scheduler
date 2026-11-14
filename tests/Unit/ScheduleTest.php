<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\NextRun;
use IndianOpinion\ImportScheduler\RunResult;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class ScheduleTest extends TestCase
{
    /** T25 (4.6.1) */
    public function testMissedSlotsAreNeverReplayed(): void
    {
        $this->rule('r', 2, null, ['interval' => 300]);
        $this->plugin->operator()->rules(true);
        $now = $this->db->now();
        $this->storage->updateRuleState('r', ['next_run' => $now - 900]);
        $this->tick();
        $this->tick();
        $this->tick();
        $this->assertCount(1, $this->runs('r'), 'one catch-up run, not three');
        $this->assertSame($now + 2 + 300, $this->state('r')['next_run']);

        // On schedule: next run keeps its rhythm from the scheduled time.
        $this->clock->advance(300);
        $scheduled = $this->state('r')['next_run'];
        $this->tick();
        $this->assertSame($scheduled + 300, $this->state('r')['next_run']);
    }

    /** T26 (4.6.2, 4.7.1) */
    public function testAnOverrunRestsAFullIntervalFromItsFinish(): void
    {
        $this->rule('slow', 400, RunResult::success(9), ['interval' => 300, 'max_runtime' => 120]);
        $start = $this->db->now();
        $this->tick();
        $run = $this->runs('slow')[0];
        $this->assertSame('overrun', $run['status']);
        $this->assertSame('success', $run['original_status']);
        $this->assertSame(400000, $run['duration_ms']);
        $this->assertSame(9, $run['items']);
        $this->assertSame($start + 400, $run['finished']);
        $this->assertSame($start + 400 + 300, $this->state('slow')['next_run']);
    }

    /** T27 (4.6.3) */
    public function testFailureBackoffDoublesToTheCapAndSuccessResetsIt(): void
    {
        $this->assertSame(300, NextRun::backoff(300, 1, 21600));
        $this->assertSame(600, NextRun::backoff(300, 2, 21600));
        $this->assertSame(1200, NextRun::backoff(300, 3, 21600));
        $this->assertSame(21600, NextRun::backoff(300, 20, 21600));
        $this->assertSame(86400, NextRun::backoff(86400, 5, 21600), 'cap is at least the interval');
        $this->assertSame(21600, NextRun::backoff(300, 1000, 21600), 'no overflow');

        $result = false;
        $this->registry->register('flaky', static function () use (&$result) {
            return $result;
        }, ['interval' => 300]);
        $this->settings(['backoff_cap' => 1000]);
        $gaps = [];
        for ($i = 0; $i < 4; $i++) {
            $this->plugin->operator()->rules(true);
            $this->storage->updateRuleState('flaky', ['next_run' => $this->db->now()]);
            $this->tick();
            $gaps[] = $this->state('flaky')['next_run'] - $this->db->now();
        }
        $this->assertSame([300, 600, 1000, 1000], $gaps);
        $this->assertSame(4, $this->state('flaky')['consecutive_failures']);
        $result = true;
        $this->storage->updateRuleState('flaky', ['next_run' => $this->db->now()]);
        $this->tick();
        $this->assertSame(0, $this->state('flaky')['consecutive_failures']);
        $this->assertSame($this->db->now() + 300, $this->state('flaky')['next_run']);
    }

    /** T28 (4.6.5, 4.6.6, 4.6.7) */
    public function testRunNowIntervalChangeAndResume(): void
    {
        $this->rule('r', 0, null, ['interval' => 3600]);
        $this->tick();
        $now = $this->db->now();
        $this->assertSame($now + 3600, $this->state('r')['next_run']);

        $this->plugin->operator()->markDue('r');
        $this->assertSame($now, $this->state('r')['next_run']);
        $this->plugin->operator()->pause('r');
        $this->tick();
        $this->assertCount(1, $this->runs('r'), 'run now does not bypass a pause');
        $this->plugin->operator()->resume('r');
        $this->tick();
        $this->assertCount(2, $this->runs('r'));

        $this->plugin->operator()->setInterval('r', 600);
        $this->assertSame($this->db->now() + 600, $this->state('r')['next_run'], 'pulled in when sooner');
        $this->assertSame(600, $this->state('r')['interval_override']);
        $this->plugin->operator()->setInterval('r', 7200);
        $this->assertSame($this->db->now() + 600, $this->state('r')['next_run'], 'left alone when later');
        $this->plugin->operator()->resetInterval('r');
        $this->assertNull($this->state('r')['interval_override']);

        // Resume with a next run long past: one run, not a burst.
        $this->plugin->operator()->pause('r', 'maintenance');
        $this->clock->advance(86400);
        $this->tick();
        $this->assertCount(2, $this->runs('r'));
        $this->plugin->operator()->resume('r');
        $this->tick();
        $this->tick();
        $this->assertCount(3, $this->runs('r'));
    }

    /** T29 (4.6.8) */
    public function testAutoPauseAfterNFailures(): void
    {
        $this->rule('bad', 0, false, ['interval' => 300]);
        $this->rule('also-bad', 0, false, ['interval' => 300]);
        $this->settings(['pause_after_failures' => 2]);
        for ($i = 0; $i < 3; $i++) {
            $this->plugin->operator()->markDue('bad');
            $this->tick();
        }
        $this->assertCount(2, $this->runs('bad'));
        $this->assertSame(1, $this->state('bad')['paused']);
        $this->assertSame('too many failures', $this->state('bad')['pause_reason']);
        $paused = \WpStub::fired('import_scheduler_rule_paused');
        $this->assertSame([['bad', 'too many failures']], $paused);
        $this->assertSame(0, $this->state('also-bad')['paused'], 'one failure is below the threshold');

        $this->settings(['pause_after_failures' => 0]);
        $this->rule('never', 0, false, ['interval' => 300]);
        for ($i = 0; $i < 5; $i++) {
            $this->plugin->operator()->markDue('never');
            $this->tick();
        }
        $this->assertSame(0, $this->state('never')['paused']);
        $this->assertSame(5, $this->state('never')['consecutive_failures']);
    }

    /** T41 (4.8.10) */
    public function testANextRunFarInTheFutureIsPulledIn(): void
    {
        $this->rule('r', 0, null, ['interval' => 300]);
        $this->rule('backing-off', 0, false, ['interval' => 300]);
        $this->tick();
        $now = $this->db->now();
        $this->storage->updateRuleState('r', ['next_run' => $now + 10 * 86400]);
        $this->storage->updateRuleState('backing-off', ['consecutive_failures' => 6, 'next_run' => $now + 9000]);
        $this->tick();
        $this->assertSame($now + 300, $this->state('r')['next_run']);
        $this->assertSame($now + 9000, $this->state('backing-off')['next_run'], 'a real backoff is not a clock error');
    }
}
