<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\Pruner;
use IndianOpinion\ImportScheduler\Settings;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class PruneTest extends TestCase
{
    /** T63 (6.2) */
    public function testPruningKeepsTheNewestNDropsOldRunsAndRunsOnceADay(): void
    {
        $now = $this->db->now();
        for ($i = 0; $i < 12; $i++) {
            $this->storage->insertRun(['rule_id' => 'busy', 'lane' => 'default', 'started' => $now - 100 + $i, 'status' => 'success', 'duration_ms' => 10]);
        }
        $this->storage->insertRun(['rule_id' => 'old', 'lane' => 'default', 'started' => $now - 40 * 86400, 'status' => 'success']);
        $this->storage->insertRun(['rule_id' => 'old', 'lane' => 'default', 'started' => $now - 86400, 'status' => 'success']);
        $this->storage->insertTick(['lane' => 'default', 'started' => $now - 8 * 86400, 'finished' => $now - 8 * 86400, 'outcome' => 'ran']);
        $this->storage->insertTick(['lane' => 'default', 'started' => $now - 86400, 'finished' => $now - 86400, 'outcome' => 'ran']);

        $settings = new Settings(['history_keep' => 5, 'history_days' => 30]);
        $pruner = new Pruner($this->storage, new Locks($this->storage));
        $this->assertSame(['runs' => 8, 'ticks' => 1], $pruner->run($now, $settings, true), 'dry run counts');
        $this->assertCount(14, $this->db->runs);

        $result = $pruner->maybeRun($now - ($now % 3600), $settings, 115);
        $this->assertSame(['runs' => 8, 'ticks' => 1], $result);
        $busy = array_column($this->runs('busy'), 'started');
        $this->assertCount(5, $busy);
        $this->assertSame($now - 100 + 7, min($busy), 'the newest five stay');
        $this->assertSame([$now - 86400], array_column($this->runs('old'), 'started'));
        $this->assertCount(1, $this->db->ticks, 'tick records go after seven days');

        $this->assertNull($pruner->maybeRun($now - ($now % 3600) + 3600, $settings, 115), 'at most once a day');
        $this->assertNull($pruner->maybeRun($now - ($now % 3600) + 500, $settings, 115), 'only in the first minutes of an hour');
        $this->clock->advance(86400);
        $this->assertNotNull($pruner->maybeRun($this->db->now() - ($this->db->now() % 3600), $settings, 115), 'again the next day');

        // A tick prunes by itself.
        $this->db->locks = [];
        for ($i = 0; $i < 60; $i++) {
            $this->storage->insertRun(['rule_id' => 'busy', 'lane' => 'default', 'started' => $this->db->now() - 1000 + $i, 'status' => 'success']);
        }
        $this->rule('busy');
        $this->db->setNow($this->db->now() - ($this->db->now() % 3600) + 10);
        $this->tick();
        $this->assertCount(50, $this->runs('busy'));
    }
}
