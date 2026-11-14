<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\Tests\TestCase;

/**
 * Regressions found in the 0.1.0 review.
 */
final class ReviewRegressionTest extends TestCase
{
    /** 4.3.6, 4.3.7: the due list read at tick start can go stale. */
    public function testARuleRunElsewhereOrPausedAfterTheDueListWasReadIsSkipped(): void
    {
        $storage = $this->storage;
        $clock = $this->clock;
        $interfere = false;
        $this->registry->register('a', static function () use ($storage, $clock, &$interfere): void {
            $clock->advance(1);
            if (!$interfere) {
                return;
            }
            // Another slot runs b to completion meanwhile, and an operator pauses c.
            $storage->updateRuleState('b', ['next_run' => $storage->now() + 300, 'last_status' => 'success']);
            $storage->updateRuleState('c', ['paused' => 1, 'pause_reason' => 'by hand']);
        }, ['interval' => 300]);
        $this->rule('b');
        $this->rule('c');
        $this->tick(); // creates rows; all three due and run once
        $this->assertCount(1, $this->runs('b'));

        $this->clock->advance(300);
        $interfere = true;
        foreach (['a', 'b', 'c'] as $id) {
            $this->storage->updateRuleState($id, ['next_run' => $this->db->now() - 10]);
        }
        [$code, $lines] = $this->tick();
        $this->assertSame(0, $code);
        $this->assertCount(2, $this->runs('a'));
        $this->assertCount(1, $this->runs('b'), 'b already ran elsewhere: not run twice');
        $this->assertCount(1, $this->runs('c'), 'c was paused mid-tick: not run');
        $this->assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_contains($l, 'b skipped: no longer due')));
        $this->assertNull($this->storage->getLock(Locks::rule('b')), 'the skipped rule lock is released');
        $this->assertNull($this->storage->getLock(Locks::rule('c')));
    }

    /** 4.6.1: a manual run before the rule is due never pushes its schedule out. */
    public function testAnEarlyManualRunCountsFromItsOwnStart(): void
    {
        $this->rule('r', 2, null, ['interval' => 600]);
        $this->tick();
        $first = $this->state('r')['next_run'];
        $this->clock->advance(60);
        $start = $this->db->now();
        [$cli, $io] = $this->cli();
        $cli->run(['r'], []);
        $this->assertSame(0, $io->code());
        $this->assertSame($start + 600, $this->state('r')['next_run']);
        $this->assertLessThan($first + 600, $this->state('r')['next_run']);
    }

    /** 4.5.5: lane and interval overrides count towards the slot expiry. */
    public function testTheSlotCoversARuleMovedInByAnOperator(): void
    {
        $this->rule('quick', 0, null, ['interval' => 300, 'max_runtime' => 30, 'lane' => 'fast']);
        $this->rule('slow', 0, null, ['interval' => 7200, 'max_runtime' => 3600]);
        $this->tick('fast');
        $this->plugin->operator()->moveLane('slow', 'fast');
        $expires = null;
        $storage = $this->storage;
        add_action('import_scheduler_run_started', static function () use ($storage, &$expires): void {
            $lock = $storage->getLock(Locks::laneSlot('fast', 1));
            $expires = $lock !== null ? $lock['expires'] - $storage->now() : null;
        });
        $this->tick('fast');
        $this->assertNotNull($expires);
        $this->assertGreaterThanOrEqual(55 + 3600 + 60, $expires);
    }

    /** 4.3.9: a tick that lost its slot stops choosing rules. */
    public function testATickThatLostItsSlotStops(): void
    {
        $storage = $this->storage;
        $this->registry->register('first', static function () use ($storage): void {
            $storage->deleteLock(Locks::laneSlot('default', 1));
            $storage->insertLock(Locks::laneSlot('default', 1), str_repeat('f', 32), 600);
        }, ['interval' => 300]);
        $this->rule('second');
        [, $lines] = $this->tick();
        $this->assertCount(1, $this->runs('first'));
        $this->assertCount(0, $this->runs('second'));
        $this->assertStringContainsString('stopped: lane slot lost', (string) end($lines));
        $this->assertSame(str_repeat('f', 32), $this->storage->getLock(Locks::laneSlot('default', 1))['owner'] ?? null, 'the other tick keeps its slot');
    }

    /** 4.8.6: commands other than tick report missing tables as a WP-CLI error. */
    public function testCommandsReportMissingTablesAsAnError(): void
    {
        $this->rule('r');
        $this->db->tablesMissing = true;
        foreach (['list_' => [], 'status' => [], 'run' => ['r'], 'history' => ['r'], 'unlock' => ['rule:r'], 'prune' => []] as $command => $args) {
            [$cli, $io] = $this->cli();
            $cli->$command($args, []);
            $this->assertSame(1, $io->code(), $command);
            $this->assertStringContainsString('tables are missing', $io->text(), $command);
        }
        $this->assertSame(0, $this->db->installs, 'only the tick creates tables');
    }
}
