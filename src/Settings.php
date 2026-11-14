<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Plugin settings: defaults, ranges and validation. Immutable.
 *
 * Stored as one array option (not autoloaded). The constant
 * IMPORT_SCHEDULER_DISABLE wins over the "enabled" setting.
 */
final class Settings
{
    public const OPTION = 'import_scheduler_settings';

    public const DEFAULTS = [
        'enabled' => true,
        'tick_budget' => 55,
        'expected_cadence' => 60,
        'default_max_runtime' => 300,
        'lock_grace' => 60,
        'lane_concurrency' => ['default' => 1],
        'paused_lanes' => [],
        'pause_after_failures' => 0,
        'backoff_cap' => 21600,
        'memory_ceiling_pct' => 80,
        'min_free_disk_mb' => 0,
        'min_available_memory_mb' => 0,
        'history_keep' => 50,
        'history_days' => 30,
        'wp_cron_fallback' => false,
    ];

    /** Integer settings: [min, max]. */
    public const RANGES = [
        'tick_budget' => [5, 3600],
        'expected_cadence' => [60, 3600],
        'default_max_runtime' => [10, 21600],
        'lock_grace' => [10, 600],
        'pause_after_failures' => [0, 100],
        'backoff_cap' => [60, 604800],
        'memory_ceiling_pct' => [50, 95],
        'min_free_disk_mb' => [0, PHP_INT_MAX],
        'min_available_memory_mb' => [0, PHP_INT_MAX],
        'history_keep' => [5, 1000],
        'history_days' => [1, 365],
    ];

    public const MAX_CONCURRENCY = 8;

    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values Already valid values; unknown keys are dropped.
     */
    public function __construct(array $values = [], private ?bool $constantDisabled = null)
    {
        $merged = self::DEFAULTS;
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                $merged[$key] = $value;
            }
        }
        $this->values = self::sanitiseStored($merged);
    }

    /**
     * Read from the option store. With $fresh the options cache entry is
     * dropped first, so a long run sees a kill switch flipped elsewhere.
     */
    public static function load(bool $fresh = false): self
    {
        if ($fresh && function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION, 'options');
        }
        $stored = function_exists('get_option') ? get_option(self::OPTION, []) : [];
        $values = is_array($stored) ? $stored : [];
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('import_scheduler_settings', array_merge(self::DEFAULTS, $values));
            $values = is_array($filtered) ? $filtered : $values;
        }
        /** @var array<string, mixed> $values */
        return new self($values);
    }

    public function enabled(): bool
    {
        if ($this->constantDisabled()) {
            return false;
        }
        return (bool) $this->values['enabled'];
    }

    public function constantDisabled(): bool
    {
        if ($this->constantDisabled !== null) {
            return $this->constantDisabled;
        }
        return defined('IMPORT_SCHEDULER_DISABLE') && (bool) constant('IMPORT_SCHEDULER_DISABLE');
    }

    public function int(string $key): int
    {
        $value = $this->values[$key] ?? 0;
        return is_numeric($value) ? (int) $value : 0;
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }

    public function laneConcurrency(string $lane): int
    {
        /** @var array<string, int> $map */
        $map = $this->values['lane_concurrency'];
        $slots = $map[$lane] ?? 1;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('import_scheduler_lane_concurrency', $slots, $lane);
            $slots = is_numeric($filtered) ? (int) $filtered : $slots;
        }
        return max(1, min(self::MAX_CONCURRENCY, $slots));
    }

    public function isLanePaused(string $lane): bool
    {
        /** @var list<string> $paused */
        $paused = $this->values['paused_lanes'];
        return in_array($lane, $paused, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Return a copy with some values replaced, after validation.
     *
     * @param array<string, mixed> $changes
     * @return array{0: self, 1: list<string>} The new settings and any errors.
     *         On any error the previous value of that key is kept.
     */
    public function with(array $changes): array
    {
        [$values, $errors] = self::validate($changes, $this->values);
        return [new self($values, $this->constantDisabled), $errors];
    }

    /**
     * Validate input against the ranges. Invalid values are reported and the
     * previous value is kept.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $previous
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function validate(array $input, array $previous): array
    {
        $values = $previous;
        $errors = [];
        foreach ($input as $key => $raw) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                $errors[] = sprintf('Unknown setting "%s".', $key);
                continue;
            }
            if (isset(self::RANGES[$key])) {
                [$min, $max] = self::RANGES[$key];
                if ((!is_int($raw) && !(is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1))) {
                    $errors[] = sprintf('%s must be a whole number.', $key);
                    continue;
                }
                $int = (int) $raw;
                if ($int < $min || $int > $max) {
                    $errors[] = $max === PHP_INT_MAX
                        ? sprintf('%s must be %d or more.', $key, $min)
                        : sprintf('%s must be between %d and %d.', $key, $min, $max);
                    continue;
                }
                $values[$key] = $int;
                continue;
            }
            if ($key === 'enabled' || $key === 'wp_cron_fallback') {
                $bool = self::toBool($raw);
                if ($bool === null) {
                    $errors[] = sprintf('%s must be true or false.', $key);
                    continue;
                }
                $values[$key] = $bool;
                continue;
            }
            if ($key === 'lane_concurrency') {
                if (!is_array($raw)) {
                    $errors[] = 'lane_concurrency must map lane names to 1 to 8 slots.';
                    continue;
                }
                $map = [];
                $ok = true;
                foreach ($raw as $lane => $slots) {
                    $slotsOk = is_int($slots) || (is_string($slots) && ctype_digit($slots));
                    if (!is_string($lane) || !Rule::isValidLane($lane) || !$slotsOk || (int) $slots < 1 || (int) $slots > self::MAX_CONCURRENCY) {
                        $ok = false;
                        break;
                    }
                    $map[$lane] = (int) $slots;
                }
                if (!$ok) {
                    $errors[] = 'lane_concurrency must map lane names to 1 to 8 slots.';
                    continue;
                }
                $values[$key] = $map;
                continue;
            }
            if ($key === 'paused_lanes') {
                if (!is_array($raw)) {
                    $errors[] = 'paused_lanes must be a list of lane names.';
                    continue;
                }
                $lanes = [];
                foreach ($raw as $lane) {
                    if (!is_string($lane) || !Rule::isValidLane($lane)) {
                        $errors[] = 'paused_lanes must be a list of lane names.';
                        continue 2;
                    }
                    $lanes[] = $lane;
                }
                $values[$key] = array_values(array_unique($lanes));
            }
        }
        return [$values, $errors];
    }

    /**
     * @param mixed $raw
     */
    private static function toBool($raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === 1 || $raw === '1' || $raw === 'true' || $raw === 'on' || $raw === 'yes') {
            return true;
        }
        if ($raw === 0 || $raw === '0' || $raw === 'false' || $raw === 'off' || $raw === 'no' || $raw === '') {
            return false;
        }
        return null;
    }

    /**
     * Stored values may have been edited by hand; anything invalid falls back
     * to the default.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function sanitiseStored(array $values): array
    {
        [$clean] = self::validate($values, self::DEFAULTS);
        return $clean;
    }
}
