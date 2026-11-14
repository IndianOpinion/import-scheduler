<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\RunResult;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class CliTest extends TestCase
{
    /** T46 (4.10.1 to 4.10.14) */
    public function testEveryCommand(): void
    {
        $this->rule('alpha', 1, RunResult::success(3, 'emitted 3, skipped 0'), ['label' => 'Alpha']);
        $this->rule('beta', 0, false, ['lane' => 'slow']);

        // tick, with --quiet keeping only failures and the summary.
        [$cli, $io] = $this->cli(true);
        $cli->tick([], ['lane' => 'default']);
        $this->assertSame(0, $io->code());
        $this->assertCount(1, $io->lines, 'quiet: summary only');
        $this->assertStringContainsString('default tick: ran, ran 1, failed 0', $io->lines[0]);
        [$cli, $io] = $this->cli(true);
        $cli->tick([], ['lane' => 'slow', 'strict' => true]);
        $this->assertSame(1, $io->code());
        $this->assertCount(2, $io->lines, 'quiet still prints the failure');
        $this->assertStringContainsString('slow beta failure', $io->lines[0]);
        [$cli, $io] = $this->cli();
        $cli->tick([], ['budget' => '2']);
        $this->assertSame(1, $io->code());
        $this->assertStringContainsString('--budget must be 5 to 3600', $io->text());
        [$cli, $io] = $this->cli();
        $cli->tick([], ['lane' => 'default', 'dry-run' => true]);
        $this->assertStringContainsString('dry run: lane default', $io->text());

        // list, each format, filters.
        foreach (['table' => "id\tlabel", 'json' => '"id":"alpha"', 'csv' => 'id,label,lane', 'yaml' => '- id: alpha', 'ids' => 'alpha beta'] as $format => $expect) {
            [$cli, $io] = $this->cli();
            $cli->list_([], ['format' => $format]);
            $this->assertStringContainsString($expect, $io->text(), $format);
        }
        [$cli, $io] = $this->cli();
        $cli->list_([], ['lane' => 'slow', 'format' => 'ids']);
        $this->assertSame(['beta'], $io->lines);
        [$cli, $io] = $this->cli();
        $cli->list_([], ['status' => 'failing', 'format' => 'ids']);
        $this->assertSame(['beta'], $io->lines);
        [$cli, $io] = $this->cli();
        $cli->list_([], ['format' => 'xml']);
        $this->assertSame(1, $io->code());

        // status
        $this->storage->insertLock('rule:alpha', str_repeat('f', 32), 60);
        [$cli, $io] = $this->cli();
        $cli->status([], []);
        $text = $io->text();
        $this->assertStringContainsString('Kill switch: off', $text);
        $this->assertStringContainsString("default\t1\t1\tno", $text);
        $this->assertStringContainsString("rule:alpha\tffffffff", $text);
        $this->assertStringContainsString('Guards for default: pass', $text);
        unset($this->db->locks['rule:alpha']);

        // run
        [$cli, $io] = $this->cli();
        $cli->run(['alpha'], []);
        $this->assertSame(0, $io->code());
        $this->assertStringContainsString('alpha success', $io->text());
        $this->assertCount(2, $this->runs('alpha'));
        [$cli, $io] = $this->cli();
        $cli->run(['beta'], []);
        $this->assertSame(1, $io->code(), 'exit 1 on failure');

        // due, pause, resume (several ids), interval, lane, stop, history
        [$cli, $io] = $this->cli();
        $cli->due(['alpha', 'beta'], []);
        $this->assertSame($this->db->now(), $this->state('beta')['next_run']);
        [$cli, $io] = $this->cli();
        $cli->pause(['alpha', 'beta'], ['reason' => 'upstream down']);
        $this->assertSame('upstream down', $this->state('alpha')['pause_reason']);
        [$cli, $io] = $this->cli();
        $cli->run(['alpha'], []);
        $this->assertSame(1, $io->code(), 'paused rules refuse without --ignore-pause');
        [$cli, $io] = $this->cli();
        $cli->run(['alpha'], ['ignore-pause' => true]);
        $this->assertSame(0, $io->code());
        [$cli, $io] = $this->cli();
        $cli->resume(['alpha', 'beta'], []);
        $this->assertSame(0, $this->state('beta')['paused']);
        [$cli, $io] = $this->cli();
        $cli->interval(['alpha', '900'], []);
        $this->assertSame(900, $this->state('alpha')['interval_override']);
        [$cli, $io] = $this->cli();
        $cli->interval(['alpha', '30'], []);
        $this->assertSame(1, $io->code());
        [$cli, $io] = $this->cli();
        $cli->interval(['alpha'], ['reset' => true]);
        $this->assertNull($this->state('alpha')['interval_override']);
        [$cli, $io] = $this->cli();
        $cli->lane(['alpha', 'slow'], []);
        $this->assertSame('slow', $this->state('alpha')['lane']);
        [$cli, $io] = $this->cli();
        $cli->lane(['alpha', 'Bad Lane'], []);
        $this->assertSame(1, $io->code());
        [$cli, $io] = $this->cli();
        $cli->stop(['alpha'], []);
        $this->assertStringContainsString('not running', $io->text());
        [$cli, $io] = $this->cli();
        $cli->history(['alpha'], ['limit' => '1', 'format' => 'json']);
        $rows = json_decode($io->lines[0], true);
        $this->assertCount(1, $rows);
        $this->assertSame(['id', 'started', 'duration', 'status', 'items', 'message'], array_keys($rows[0]));

        // pause-lane and resume-lane (the per-lane switch)
        [$cli, $io] = $this->cli();
        $cli->pauseLane(['slow'], []);
        $this->assertTrue($this->plugin->settings()->isLanePaused('slow'));
        $this->assertSame([0, ['stood down: lane paused']], $this->tick('slow'));
        [$cli, $io] = $this->cli();
        $cli->resumeLane(['slow'], []);
        $this->assertFalse($this->plugin->settings()->isLanePaused('slow'));

        // unlock, prune
        $this->storage->insertLock('rule:x', Locks::newOwner(), 1);
        $this->clock->advance(2);
        [$cli, $io] = $this->cli();
        $cli->unlock(['rule:x'], []);
        $this->assertStringContainsString('Removed rule:x', $io->text());
        [$cli, $io] = $this->cli();
        $cli->unlock(['rule:none'], []);
        $this->assertSame(1, $io->code());
        $this->storage->insertRuleStates([\IndianOpinion\ImportScheduler\Sync::newRow(\IndianOpinion\ImportScheduler\Rule::fromArgs('ghost', static fn () => null, ['interval' => 60]), 1, new \IndianOpinion\ImportScheduler\NextRun())]);
        [$cli, $io] = $this->cli();
        $cli->prune([], ['orphans' => true, 'dry-run' => true]);
        $this->assertStringContainsString('Would forget 1 orphaned rules', $io->text());
        $this->assertNotNull($this->storage->getRuleState('ghost'));
        [$cli, $io] = $this->cli();
        $cli->prune([], ['orphans' => true]);
        $this->assertStringContainsString('Forgot 1 orphaned rules', $io->text());
        $this->assertNull($this->storage->getRuleState('ghost'));
        $this->assertNotNull($this->storage->getRuleState('alpha'));

        // Unknown ids exit 1 with "no such rule".
        foreach (['run', 'due', 'pause', 'resume', 'stop', 'history'] as $command) {
            [$cli, $io] = $this->cli();
            $cli->$command(['nope'], []);
            $this->assertSame(1, $io->code(), $command);
            $this->assertStringContainsString('no such rule', $io->text(), $command);
        }
        [$cli, $io] = $this->cli();
        $cli->interval(['nope', '600'], []);
        $this->assertSame(1, $io->code());
        [$cli, $io] = $this->cli();
        $cli->due(['alpha', 'nope'], []);
        $this->assertSame(1, $io->code(), 'one unknown id fails the command');
        $this->assertStringContainsString('alpha: due', $io->text(), 'the known id is still handled');
    }

    /** T47 (4.10.13) */
    public function testCronLinePrintsPlaceholdersAndWritesNoFile(): void
    {
        $dir = sys_get_temp_dir() . '/is-cron-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $cwd = getcwd();
        chdir($dir);
        try {
            [$cli, $io] = $this->cli();
            $cli->cronLine([], []);
            $this->assertSame(['* * * * * <user> cd <path> && wp import-scheduler tick --lane=default --quiet >> <log file> 2>&1'], $io->lines);
            [$cli, $io] = $this->cli();
            $cli->cronLine([], ['lane' => 'slow', 'user' => 'www-data', 'path' => '/srv/site']);
            $this->assertSame(['* * * * * www-data cd /srv/site && wp import-scheduler tick --lane=slow --quiet >> <log file> 2>&1'], $io->lines);
            [$cli, $io] = $this->cli();
            $cli->cronLine([], ['lane' => 'Not Valid']);
            $this->assertSame(1, $io->code());
            $this->assertSame(['.', '..'], scandir($dir));
        } finally {
            chdir((string) $cwd);
            rmdir($dir);
        }
    }
}
