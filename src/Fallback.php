<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Optional WP-Cron fallback: one event per lane at the expected cadence,
 * running the same tick code inside page requests. Off by default. When a
 * system cron tick was seen in the last hour the event exits at once.
 */
final class Fallback
{
    public const HOOK = 'import_scheduler_fallback_tick';
    public const SCHEDULE = 'import_scheduler_cadence';

    public function __construct(private Plugin $plugin)
    {
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function addSchedule($schedules): array
    {
        $schedules = is_array($schedules) ? $schedules : [];
        $schedules[self::SCHEDULE] = [
            'interval' => $this->plugin->settings()->int('expected_cadence'),
            'display' => 'Import scheduler cadence',
        ];
        return $schedules;
    }

    /**
     * Keep the events in line with the setting and the known lanes.
     *
     * @param list<string> $lanes
     */
    public function reconcile(array $lanes): void
    {
        if (!$this->plugin->settings()->bool('wp_cron_fallback')) {
            wp_unschedule_hook(self::HOOK);
            return;
        }
        foreach ($lanes as $lane) {
            if (wp_next_scheduled(self::HOOK, [$lane]) === false) {
                wp_schedule_event(time(), self::SCHEDULE, self::HOOK, [$lane]);
            }
        }
    }

    /**
     * The event handler.
     *
     * @param mixed $lane
     */
    public function handle($lane = Rule::DEFAULT_LANE): int
    {
        $lane = is_string($lane) ? $lane : Rule::DEFAULT_LANE;
        if (!$this->plugin->settings()->bool('wp_cron_fallback')) {
            return 0;
        }
        return $this->plugin->tick()->run($lane, null, false, false, static function (string $line, bool $important): void {
            if ($important && str_contains($line, 'failed') && !str_contains($line, 'failed 0')) {
                Log::error($line);
            }
        }, Tick::SOURCE_WP_CRON);
    }
}
