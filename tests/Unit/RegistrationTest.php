<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Adapter;
use IndianOpinion\ImportScheduler\Exception\InvalidRuleException;
use IndianOpinion\ImportScheduler\NextRun;
use IndianOpinion\ImportScheduler\Plugin;
use IndianOpinion\ImportScheduler\Registry;
use IndianOpinion\ImportScheduler\Rule;
use IndianOpinion\ImportScheduler\RunContext;
use IndianOpinion\ImportScheduler\RunResult;
use IndianOpinion\ImportScheduler\Sync;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class RegistrationTest extends TestCase
{
    /** T1 (4.2.1) */
    public function testRulesRegisteredBeforeDuringAndAfterTheActionAreAllPresent(): void
    {
        $registry = new Registry();
        $plugin = $this->makePlugin($this->storage, $registry);
        Plugin::setInstance($plugin);
        $noop = static fn () => null;

        $this->assertTrue(import_scheduler_register('before', $noop, ['interval' => 60]));
        $this->assertFalse($registry->has('before'), 'queued until the action fires');
        add_action('import_scheduler_register', static function () use ($noop): void {
            import_scheduler_register('during', $noop, ['interval' => 60]);
        });
        $plugin->fireRegister();
        $this->assertTrue(import_scheduler_register('after', $noop, ['interval' => 60]));

        $this->assertSame(['before', 'during', 'after'], array_keys($registry->all()));
        $this->assertSame(1, did_action('import_scheduler_register'));
        $plugin->fireRegister();
        $this->assertSame(1, did_action('import_scheduler_register'), 'fires once');
    }

    /** T2 (4.2.2, 4.2.5) */
    public function testIdsAndLanesAreValidated(): void
    {
        foreach (['Upper', 'has space', '-leading', str_repeat('a', 65), '', 'émoji'] as $bad) {
            $this->assertFalse(import_scheduler_register($bad, static fn () => null, ['interval' => 60]), $bad);
        }
        foreach (['a', 'feed_1', 'my-feed', '9lives', str_repeat('a', 64)] as $good) {
            $this->assertTrue(import_scheduler_register($good, static fn () => null, ['interval' => 60]), $good);
        }
        foreach (['Fast', 'two words', '-x', str_repeat('l', 33)] as $badLane) {
            $this->assertFalse(import_scheduler_register('lane-' . md5($badLane), static fn () => null, ['interval' => 60, 'lane' => $badLane]), $badLane);
        }
        $this->assertTrue(import_scheduler_register('lane-ok', static fn () => null, ['interval' => 60, 'lane' => 'slow_feeds-2']));
        $this->assertSame('slow_feeds-2', $this->registry->get('lane-ok')?->lane);
        $this->assertSame('default', $this->registry->get('a')?->lane);
    }

    /** T3 (4.2.3) */
    public function testDuplicateRegistrationIsRejectedAndListed(): void
    {
        $first = static fn () => 'first';
        $this->assertTrue(import_scheduler_register('dup', $first, ['interval' => 60]));
        $this->assertFalse(import_scheduler_register('dup', static fn () => 'second', ['interval' => 60]));
        $this->assertSame($first, $this->registry->get('dup')?->runner());
        $this->assertCount(1, $this->registry->problems());
        $this->assertStringContainsString('already registered', $this->registry->problems()[0]);
        $this->assertNotEmpty($GLOBALS['wp_stub']['doing_it_wrong']);
    }

    /** T4 (4.2.4, 4.2.6) */
    public function testIntervalAndRuntimeRangesAreRejectedNotClamped(): void
    {
        $noop = static fn () => null;
        $this->assertFalse(import_scheduler_register('i59', $noop, ['interval' => 59]));
        $this->assertFalse(import_scheduler_register('ibig', $noop, ['interval' => 2592001]));
        $this->assertFalse(import_scheduler_register('inone', $noop, []));
        $this->assertFalse(import_scheduler_register('m9', $noop, ['interval' => 600, 'max_runtime' => 9]));
        $this->assertFalse(import_scheduler_register('mbig', $noop, ['interval' => 600, 'max_runtime' => 21601]));
        $this->assertTrue(import_scheduler_register('edge', $noop, ['interval' => 60]));
        $this->assertTrue(import_scheduler_register('long', $noop, ['interval' => 2592000, 'max_runtime' => 21600]));

        $settings = $this->plugin->settings();
        $edge = $this->registry->get('edge');
        $this->assertNotNull($edge);
        $this->assertSame(60, $edge->effectiveMaxRuntime(60, $settings->int('default_max_runtime')), 'interval when shorter than 300');
        $this->assertTrue(import_scheduler_register('hour', $noop, ['interval' => 3600]));
        $this->assertSame(300, $this->registry->get('hour')?->effectiveMaxRuntime(3600, 300));
        $this->assertSame('hour', $this->registry->get('hour')?->label, 'label defaults to the id');
    }

    /** T5 (4.2.7) */
    public function testRunnerMustBeCallableOrAdapter(): void
    {
        $this->assertFalse(import_scheduler_register('str', 'no_such_function_here', ['interval' => 60]));
        $adapter = new class implements Adapter {
            public function run(RunContext $context)
            {
                return RunResult::success(1);
            }
        };
        $this->assertTrue(import_scheduler_register('adapter', $adapter, ['interval' => 60]));
        $this->assertTrue(import_scheduler_register('fn', 'strlen', ['interval' => 60]));
    }

    /** T6 (4.2.8) */
    public function testInvalidRegistrationNeverThrowsFromThePublicFunction(): void
    {
        $this->assertFalse(import_scheduler_register('Bad Id', static fn () => null, ['interval' => 'soon']));
        $this->assertFalse(import_scheduler_register('x', static fn () => null, ['interval' => 60, 'nonsense' => 1]));
        $this->expectException(InvalidRuleException::class);
        $this->registry->register('Bad Id', static fn () => null, ['interval' => 60]);
    }

    /** T7 (4.2.9, 4.2.10, 4.15.8) */
    public function testStateRowsOrphansAndForgottenIds(): void
    {
        $this->rule('keep');
        $this->rule('gone');
        $this->assertNull($this->storage->getRuleState('keep'));
        $this->tick();
        $this->assertNotNull($this->storage->getRuleState('keep'));
        $this->assertCount(1, $this->runs('gone'));

        // Next request: "gone" is no longer registered.
        $registry = new Registry();
        $registry->open();
        $registry->register('keep', static fn () => null, ['interval' => 300]);
        $plugin = $this->makePlugin($this->storage, $registry);
        $this->plugin->operator()->markDue('gone');
        $this->tick('default', null, false, false, $plugin);
        $this->assertCount(1, $this->runs('gone'), 'an orphan is never run');
        $this->assertSame(1, $this->state('gone')['tick_orphan']);
        $this->assertNotNull($this->storage->getRuleState('gone'), 'and never deleted automatically');
        $this->assertSame('orphan', $plugin->operator()->rules()['gone']->status($this->db->now()));

        // Forget it, then reuse the id: it starts fresh and is due at once.
        $plugin->operator()->forget('gone');
        $this->assertNull($this->storage->getRuleState('gone'));
        $this->assertSame([], $this->runs('gone'));
        $registry->register('gone', static fn () => 3, ['interval' => 300]);
        $this->tick('default', null, false, false, $plugin);
        $this->assertCount(1, $this->runs('gone'));
        $this->assertSame(3, $this->state('gone')['last_items']);
    }

    /** T8 (4.2.11, 4.2.12, 4.6.4) */
    public function testNewRulesStartDelayAndJitter(): void
    {
        $now = $this->db->now();
        $this->rule('now');
        $this->rule('later', 0, null, ['start_delay' => 600]);
        $this->tick();
        $this->assertCount(1, $this->runs('now'));
        $this->assertSame([], $this->runs('later'));
        $this->assertSame($now + 600, $this->state('later')['next_run']);
        $this->clock->advance(600);
        $this->tick();
        $this->assertCount(1, $this->runs('later'));

        $this->assertFalse(import_scheduler_register('toomuch', static fn () => null, ['interval' => 400, 'jitter' => 101]));
        $rule = Rule::fromArgs('jit', static fn () => null, ['interval' => 400, 'jitter' => 100]);
        $real = new NextRun();
        for ($i = 0; $i < 50; $i++) {
            $next = $real->afterSuccess(1000, 1000, 400, $rule->jitter);
            $this->assertGreaterThanOrEqual(1400, $next);
            $this->assertLessThanOrEqual(1500, $next);
            $first = Sync::newRow(Rule::fromArgs('d', static fn () => null, ['interval' => 400, 'jitter' => 100, 'start_delay' => 50]), 1000, $real)['next_run'];
            $this->assertGreaterThanOrEqual(1050, $first);
            $this->assertLessThanOrEqual(1150, $first);
        }
    }
}
