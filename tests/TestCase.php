<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests;

use IndianOpinion\ImportScheduler\Cli\Command;
use IndianOpinion\ImportScheduler\Guards;
use IndianOpinion\ImportScheduler\Log;
use IndianOpinion\ImportScheduler\Memory;
use IndianOpinion\ImportScheduler\NextRun;
use IndianOpinion\ImportScheduler\Plugin;
use IndianOpinion\ImportScheduler\Registry;
use IndianOpinion\ImportScheduler\Runner;
use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\Settings;
use IndianOpinion\ImportScheduler\Tests\Support\BufferIo;
use IndianOpinion\ImportScheduler\Tests\Support\MemoryDatabase;
use IndianOpinion\ImportScheduler\Tests\Support\MemoryStorage;
use IndianOpinion\ImportScheduler\Tests\Support\TestClock;

/**
 * Builds a plugin over the in-memory database, with a test clock, settings
 * held in memory and the error log captured.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected TestClock $clock;
    protected MemoryDatabase $db;
    protected MemoryStorage $storage;
    protected Registry $registry;
    protected Plugin $plugin;

    /** @var array<string, mixed> */
    protected array $settingValues = [];

    /** @var list<string> */
    protected array $logged = [];

    protected int $memoryUsage = 0;
    protected string $memoryLimit = '-1';
    protected ?string $guardMeminfo = null;

    /** @var (callable(string): (float|false))|null */
    protected $diskFree = null;

    protected ?bool $constantDisabled = null;

    protected ?Runner $runner = null;

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::reset();
        $this->clock = new TestClock();
        $this->db = new MemoryDatabase($this->clock);
        $this->storage = new MemoryStorage($this->db);
        $this->registry = new Registry();
        $this->registry->open();
        $this->settingValues = [];
        $this->logged = [];
        Log::setSink(function (string $line): void {
            $this->logged[] = $line;
        });
        $this->plugin = $this->makePlugin($this->storage, $this->registry);
        Plugin::setInstance($this->plugin);
    }

    protected function tearDown(): void
    {
        Log::setSink(null);
        Plugin::setInstance(null);
        parent::tearDown();
    }

    protected function makePlugin(MemoryStorage $storage, Registry $registry): Plugin
    {
        $settings = fn (bool $fresh = false): Settings => new Settings(
            apply_filters('import_scheduler_settings', array_merge(Settings::DEFAULTS, $this->settingValues)),
            $this->constantDisabled
        );
        $this->runner = new Runner(
            $storage,
            new Locks($storage),
            $settings,
            $this->clock,
            new NextRun(static fn (int $min, int $max): int => $max),
            static fn (): ?array => null,
            false
        );
        $this->runner->stopPollSeconds = 0;
        return new Plugin(
            $storage,
            $registry,
            $settings,
            $this->clock,
            new NextRun(static fn (int $min, int $max): int => $max),
            fn (): bool => $this->db->install(),
            new Memory(fn (): int => $this->memoryUsage, fn (): string => $this->memoryLimit),
            fn (Settings $s): Guards => new Guards($s, $this->diskFree ?? static fn (string $d): float => 1e12, $this->guardMeminfo ?? '/nonexistent/meminfo', static fn (): string => '/tmp'),
            function (array $values): void {
                $this->settingValues = $values;
            },
            $this->runner
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function settings(array $values): void
    {
        $this->settingValues = array_merge($this->settingValues, $values);
    }

    /**
     * Run a tick and capture output.
     *
     * @return array{0: int, 1: list<string>}
     */
    protected function tick(string $lane = 'default', ?int $budget = null, bool $dryRun = false, bool $strict = false, ?\IndianOpinion\ImportScheduler\Plugin $plugin = null): array
    {
        $lines = [];
        $code = ($plugin ?? $this->plugin)->tick()->run($lane, $budget, $dryRun, $strict, static function (string $line, bool $important) use (&$lines): void {
            $lines[] = $line;
        });
        return [$code, $lines];
    }

    protected function cli(bool $quiet = false): array
    {
        $io = new BufferIo($quiet);
        return [new Command($this->plugin, $io), $io];
    }

    /**
     * Register a rule whose runner advances the clock by $seconds and returns $result.
     *
     * @param array<string, mixed> $args
     */
    protected function rule(string $id, int $seconds = 0, $result = null, array $args = []): void
    {
        $clock = $this->clock;
        $this->registry->register($id, static function () use ($clock, $seconds, $result) {
            $clock->advance($seconds);
            return $result;
        }, $args + ['interval' => 300]);
    }

    /**
     * @return array<string, int|string|null>
     */
    protected function state(string $id): array
    {
        $state = $this->storage->getRuleState($id);
        $this->assertNotNull($state, 'state row for ' . $id);
        return $state;
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    protected function runs(string $id): array
    {
        return array_values(array_filter($this->db->runs, static fn (array $r): bool => $r['rule_id'] === $id));
    }
}
