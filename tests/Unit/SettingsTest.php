<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Fallback;
use IndianOpinion\ImportScheduler\Settings;
use IndianOpinion\ImportScheduler\Tick;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class SettingsTest extends TestCase
{
    /** T48 (4.12.2, 4.12.1) */
    public function testTheConstantOverridesAnEnabledSetting(): void
    {
        $this->rule('r');
        $this->settings(['enabled' => false]);
        $this->assertSame([0, ['disabled']], $this->tick());
        $this->assertSame('kill switch is on', $this->db->ticks[1]['reason']);
        $this->settings(['enabled' => true]);
        $this->constantDisabled = true;
        $this->plugin = $this->makePlugin($this->storage, $this->registry);
        $this->assertSame([0, ['disabled']], $this->tick());
        $this->assertSame('IMPORT_SCHEDULER_DISABLE is set', $this->db->ticks[2]['reason']);
        $this->assertSame([], $this->db->locks);
        $this->assertSame([], $this->runs('r'));

        $this->assertFalse((new Settings(['enabled' => true], true))->enabled());
        $this->assertTrue((new Settings(['enabled' => true], false))->enabled());

        [$settings, $errors] = (new Settings())->with(['tick_budget' => '4', 'history_days' => '10', 'memory_ceiling_pct' => 'lots', 'lane_concurrency' => ['default' => 9], 'nope' => 1]);
        $this->assertSame(55, $settings->int('tick_budget'));
        $this->assertSame(10, $settings->int('history_days'));
        $this->assertSame(80, $settings->int('memory_ceiling_pct'));
        $this->assertSame(1, $settings->laneConcurrency('default'));
        $this->assertCount(4, $errors);

        add_filter('import_scheduler_lane_concurrency', static fn ($slots, $lane) => $lane === 'wide' ? 4 : $slots, 10, 2);
        $this->assertSame(4, (new Settings())->laneConcurrency('wide'));
        add_filter('import_scheduler_settings', static fn (array $s) => array_merge($s, ['tick_budget' => 30]));
        update_option(Settings::OPTION, ['tick_budget' => 40]);
        $this->assertSame(30, Settings::load()->int('tick_budget'), 'the filter has the final say');
    }

    /** T49 (4.12.3) */
    public function testTheFallbackEventExitsWhenASystemTickWasSeenInTheLastHour(): void
    {
        $this->rule('r');
        $fallback = new Fallback($this->plugin);
        $this->settings(['wp_cron_fallback' => true, 'expected_cadence' => 120]);

        $fallback->reconcile(['default', 'slow']);
        $this->assertNotFalse(wp_next_scheduled(Fallback::HOOK, ['default']));
        $this->assertNotFalse(wp_next_scheduled(Fallback::HOOK, ['slow']));
        $this->assertSame(120, $fallback->addSchedule([])[Fallback::SCHEDULE]['interval']);

        $this->storage->insertTick(['lane' => 'default', 'slot' => 1, 'source' => Tick::SOURCE_CLI, 'started' => $this->db->now() - 1800, 'finished' => $this->db->now() - 1800, 'outcome' => 'nothing_due']);
        $before = $this->db->queries;
        $this->assertSame(0, $fallback->handle('default'));
        $this->assertSame([], $this->runs('r'), 'a system tick ran recently: exit at once');
        $this->assertLessThanOrEqual(2, $this->db->queries - $before, 'the clock and one indexed read');

        $this->clock->advance(1801);
        $this->assertSame(0, $fallback->handle('default'));
        $this->assertCount(1, $this->runs('r'), 'no system tick for an hour: the fallback ticks');
        $this->assertSame(Tick::SOURCE_WP_CRON, end($this->db->ticks)['source']);

        $this->settings(['wp_cron_fallback' => false]);
        $fallback->reconcile(['default', 'slow']);
        $this->assertSame([], $GLOBALS['wp_stub']['cron']);
        $this->plugin->operator()->markDue('r');
        $this->assertSame(0, $fallback->handle('default'));
        $this->assertCount(1, $this->runs('r'), 'off means off');
    }
}
