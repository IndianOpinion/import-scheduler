<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\DbTime;
use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\NextRun;
use IndianOpinion\ImportScheduler\RunContext;
use IndianOpinion\ImportScheduler\Runner;
use IndianOpinion\ImportScheduler\RunResult;
use IndianOpinion\ImportScheduler\Settings;
use IndianOpinion\ImportScheduler\Sync;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class RunTest extends TestCase
{
    /** T17 (4.4.3, 4.4.4, 4.4.1) */
    public function testResultMappingAndOutputCapture(): void
    {
        $context = null;
        $cases = [
            'result' => [RunResult::success(4, 'done'), 'success', 4, 'done'],
            'fail' => [RunResult::failure('upstream 500'), 'failure', null, 'upstream 500'],
            'int' => [7, 'success', 7, ''],
            'null' => [null, 'success', null, ''],
            'true' => [true, 'success', null, ''],
            'false' => [false, 'failure', null, 'runner returned false'],
            'wperror' => [new \WP_Error('http', 'timed out'), 'failure', null, 'timed out'],
            'other' => ['surprise', 'success', null, 'warning: runner returned an unexpected string; treated as success'],
        ];
        foreach ($cases as $id => [$returned]) {
            $this->registry->register($id, function (RunContext $c) use ($returned, &$context) {
                $context = $c;
                return $returned;
            }, ['interval' => 300, 'args' => ['feed' => 'x']]);
        }
        $this->registry->register('throws', static function (): void {
            throw new \DomainException(str_repeat('x', 900));
        }, ['interval' => 300]);
        $this->registry->register('echoes', static function (): void {
            echo "  hello from the runner\n";
            echo str_repeat('é', 400);
        }, ['interval' => 300]);
        $this->registry->register('echo-and-message', static function () {
            echo 'noise';
            return RunResult::success(1, 'own message');
        }, ['interval' => 300]);

        $this->tick('default', 3600);
        foreach ($cases as $id => [, $status, $items, $message]) {
            $run = $this->runs($id)[0];
            $this->assertSame($status, $run['status'], $id);
            $this->assertSame($items, $run['items'], $id);
            $this->assertSame($message, $run['message'], $id);
        }
        $thrown = $this->runs('throws')[0];
        $this->assertSame('failure', $thrown['status']);
        $this->assertStringStartsWith('DomainException: xxx', (string) $thrown['message']);
        $this->assertSame(500, strlen((string) $thrown['message']));
        $echoed = (string) $this->runs('echoes')[0]['message'];
        $this->assertStringStartsWith('hello from the runner', $echoed);
        $this->assertLessThanOrEqual(500, strlen($echoed));
        $this->assertSame(1, preg_match('//u', $echoed), 'never splits a character');
        $this->assertSame('own message', $this->runs('echo-and-message')[0]['message']);

        $this->assertInstanceOf(RunContext::class, $context);
        $this->assertSame(['feed' => 'x'], $context->args());
        $this->assertSame('default', $context->lane());
        $this->assertSame($context->startedAt() + 300, $context->deadline());
        $this->assertGreaterThan(0, $context->runId());
    }

    /** T18 (4.4.5 to 4.4.8, 4.4.2) */
    public function testDurationsStateColumnsAndCounters(): void
    {
        $seen = null;
        $result = RunResult::success(2, 'fine');
        $this->registry->register('r', function () use (&$seen, &$result) {
            $seen = $this->storage->getRuleState('r');
            $this->clock->advance(1.25);
            return $result;
        }, ['interval' => 300, 'max_runtime' => 60]);
        $start = $this->db->now();
        $this->tick();
        $this->assertNotNull($seen);
        $this->assertSame($start, $seen['last_start'], 'last start written before the call');
        $this->assertNotNull($seen['running_run_id']);
        $state = $this->state('r');
        $this->assertSame(1250, $state['last_duration_ms']);
        $this->assertSame('success', $state['last_status']);
        $this->assertSame(2, $state['last_items']);
        $this->assertSame('fine', $state['last_message']);
        $this->assertSame($start, $state['last_success_start']);
        $this->assertSame($start + 1, $state['last_finish']);
        $this->assertNull($state['running_run_id']);
        $this->assertSame(1250, $this->runs('r')[0]['duration_ms']);

        $result = RunResult::failure('nope');
        $this->plugin->operator()->markDue('r');
        $this->tick();
        $this->assertSame(1, $this->state('r')['consecutive_failures']);

        // Overrun: keeps its original result in its own column, and success resets failures.
        $this->registry->register('o', function () {
            $this->clock->advance(90);
            return RunResult::success(1);
        }, ['interval' => 300, 'max_runtime' => 60]);
        $this->tick();
        $run = $this->runs('o')[0];
        $this->assertSame('overrun', $run['status']);
        $this->assertSame('success', $run['original_status']);
        $this->assertSame(1, $this->state('o')['consecutive_overruns']);
        $this->assertSame(0, $this->state('o')['consecutive_failures']);
        $this->plugin->operator()->markDue('o');
        $this->registry->get('o');
        $this->tick();
        $this->assertSame(2, $this->state('o')['consecutive_overruns']);
    }

    /** T19 (4.4.10, 5.3) */
    public function testRunStartedAndFinishedActionsCarryTheRunRecord(): void
    {
        $this->rule('r', 2, 5);
        $this->tick();
        $started = \WpStub::fired('import_scheduler_run_started');
        $finished = \WpStub::fired('import_scheduler_run_finished');
        $this->assertCount(1, $started);
        $this->assertSame('r', $started[0][0]['rule_id']);
        $this->assertSame('running', $started[0][0]['status']);
        $this->assertCount(1, $finished);
        $this->assertSame('r', $finished[0][0]['rule_id']);
        $this->assertSame('success', $finished[0][0]['status']);
        $this->assertSame(5, $finished[0][0]['items']);
        $this->assertSame(2000, $finished[0][0]['duration_ms']);
        $tick = \WpStub::fired('import_scheduler_tick_finished');
        $this->assertSame(['lane', 'slot', 'started', 'ended', 'runs', 'failures', 'skipped', 'outcome', 'reason'], array_keys($tick[0][0]));
    }

    /** T36 (4.8.1) */
    public function testAThrowingRuleFailsAndTheNextRuleStillRuns(): void
    {
        $this->registry->register('a-broken', static function (): void {
            throw new \TypeError('parser exploded');
        }, ['interval' => 300]);
        $this->rule('b-fine', 0, 3);
        $this->tick();
        $this->assertSame('failure', $this->runs('a-broken')[0]['status']);
        $this->assertSame('TypeError: parser exploded', $this->runs('a-broken')[0]['message']);
        $this->assertSame('success', $this->runs('b-fine')[0]['status']);
        $this->assertNotEmpty(array_filter($this->logged, static fn (string $l): bool => str_contains($l, '[import-scheduler] rule a-broken: run failed')));
    }

    /** T38 (4.8.3, 4.15.10) */
    public function testTheShutdownHandlerClosesARunAfterAFatalAndAfterExit(): void
    {
        $cases = [
            'fatal' => [['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => '/x/parser.php', 'line' => 12], 'PHP fatal error: Allowed memory size exhausted in parser.php:12'],
            'exits' => [null, 'runner exited'],
        ];
        foreach ($cases as $id => [$error, $message]) {
            $settings = fn (bool $fresh = false): Settings => new Settings($this->settingValues);
            $runner = new Runner($this->storage, new Locks($this->storage), $settings, $this->clock, new NextRun(), static fn (): ?array => $error, false);
            $this->registry->register($id, static function () use (&$runner): void {
                echo 'partial output';
                $runner->onShutdown(); // what PHP does when the runner dies here
            }, ['interval' => 300]);
            $now = $this->db->now();
            $this->storage->insertRuleStates([Sync::newRow($this->registry->get($id), $now, new NextRun())]);
            $slotOwner = Locks::newOwner();
            $this->storage->insertLock('lane:default:1', $slotOwner, 600);
            $runner->setSlot('lane:default:1', $slotOwner);
            $rule = $this->registry->get($id);
            $this->assertNotNull($rule);
            $state = $this->storage->getRuleState($id);
            $this->assertNotNull($state);
            $run = $runner->run($rule, $state, new DbTime($now, $this->clock));
            $this->assertNotNull($run);
            $this->assertSame('failure', $run['status'], $id);
            $this->assertSame($message, $run['message'], $id);
            $this->assertSame(1, $this->state($id)['consecutive_failures']);
            $this->assertNull($this->state($id)['running_run_id']);
            $this->assertArrayNotHasKey('rule:' . $id, $this->db->locks);
            $this->assertArrayNotHasKey('lane:default:1', $this->db->locks, 'lane slot freed too');
            $runner->onShutdown(); // a second call is harmless
        }
    }
}
