<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Pre-flight checks before a tick takes a lock. Each fails open when the
 * host cannot answer (disabled function, unreadable file), and says so.
 */
final class Guards
{
    /** @var callable(string): (float|false) */
    private $diskFree;

    /** @var callable(): string */
    private $uploadsDir;

    /** @var list<string> */
    private array $notes = [];

    /**
     * @param (callable(string): (float|false))|null $diskFree
     * @param (callable(): string)|null              $uploadsDir
     */
    public function __construct(
        private Settings $settings,
        ?callable $diskFree = null,
        private string $meminfoPath = '/proc/meminfo',
        ?callable $uploadsDir = null
    ) {
        $this->diskFree = $diskFree ?? static function (string $dir) {
            if (!function_exists('disk_free_space')) {
                return false;
            }
            set_error_handler(static fn (): bool => true);
            try {
                return disk_free_space($dir);
            } finally {
                restore_error_handler();
            }
        };
        $this->uploadsDir = $uploadsDir ?? static function (): string {
            if (function_exists('wp_upload_dir')) {
                $dir = wp_upload_dir(null, false);
                if (is_array($dir) && isset($dir['basedir']) && is_string($dir['basedir'])) {
                    return $dir['basedir'];
                }
            }
            return defined('ABSPATH') ? (string) constant('ABSPATH') : '.';
        };
    }

    /**
     * @return string|null A stand-down reason, or null to go ahead.
     */
    public function check(string $lane): ?string
    {
        $this->notes = [];
        if ($this->settings->isLanePaused($lane)) {
            return 'lane paused';
        }
        $minDisk = $this->settings->int('min_free_disk_mb');
        if ($minDisk > 0) {
            $dir = ($this->uploadsDir)();
            $free = ($this->diskFree)($dir);
            if ($free === false) {
                $this->notes[] = 'free disk guard passed: free space could not be read';
            } elseif ($free < $minDisk * 1048576) {
                return sprintf('low disk: %d MB free, minimum %d MB', (int) floor($free / 1048576), $minDisk);
            }
        }
        $minMemory = $this->settings->int('min_available_memory_mb');
        if ($minMemory > 0) {
            $available = $this->availableMemoryMb();
            if ($available === null) {
                $this->notes[] = 'memory guard passed: ' . $this->meminfoPath . ' is not readable';
            } elseif ($available < $minMemory) {
                return sprintf('low memory: %d MB available, minimum %d MB', $available, $minMemory);
            }
        }
        $custom = apply_filters('import_scheduler_should_tick', true, $lane);
        if ($custom === false) {
            return 'stood down by a filter';
        }
        if (is_string($custom)) {
            return $custom !== '' ? Text::head(Text::oneLine($custom), 191) : 'stood down by a filter';
        }
        return null;
    }

    /**
     * Why a guard passed without a reading, for the status command.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    private function availableMemoryMb(): ?int
    {
        if (!is_readable($this->meminfoPath)) {
            return null;
        }
        $content = file_get_contents($this->meminfoPath);
        if ($content === false || preg_match('/^MemAvailable:\s+(\d+)\s*kB/mi', $content, $m) !== 1) {
            return null;
        }
        return intdiv((int) $m[1], 1024);
    }
}
