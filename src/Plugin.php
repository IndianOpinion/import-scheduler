<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

use IndianOpinion\ImportScheduler\Admin\Screen;
use IndianOpinion\ImportScheduler\Storage\SqlStorage;
use IndianOpinion\ImportScheduler\Storage\Storage;
use IndianOpinion\ImportScheduler\Storage\WpdbConnection;

/**
 * Wires the parts together and hooks them into WordPress. One instance per
 * request; tests build their own with an in-memory storage.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const REGISTER_PRIORITY = 20;

    private static ?self $instance = null;

    /** @var callable(bool): Settings */
    private $settingsLoader;

    /** @var callable(array<string, mixed>): void */
    private $saveSettings;

    /** @var (callable(): bool)|null */
    private $installer;

    /** @var callable(Settings): Guards */
    private $guards;

    private Clock $clock;
    private NextRun $nextRun;
    private Memory $memory;
    private Locks $locks;
    private ?Runner $runner = null;
    private ?Installer $installerObject = null;

    /**
     * @param (callable(bool): Settings)|null             $settingsLoader
     * @param (callable(array<string, mixed>): void)|null $saveSettings
     * @param (callable(): bool)|null                     $installer
     * @param (callable(Settings): Guards)|null           $guards
     */
    public function __construct(
        private Storage $storage,
        private Registry $registry,
        ?callable $settingsLoader = null,
        ?Clock $clock = null,
        ?NextRun $nextRun = null,
        ?callable $installer = null,
        ?Memory $memory = null,
        ?callable $guards = null,
        ?callable $saveSettings = null,
        ?Runner $runner = null
    ) {
        $this->settingsLoader = $settingsLoader ?? static fn (bool $fresh = false): Settings => Settings::load($fresh);
        $this->saveSettings = $saveSettings ?? static function (array $values): void {
            update_option(Settings::OPTION, $values, false);
        };
        $this->clock = $clock ?? new SystemClock();
        $this->nextRun = $nextRun ?? new NextRun();
        $this->installer = $installer;
        $this->memory = $memory ?? new Memory();
        $this->guards = $guards ?? static fn (Settings $settings): Guards => new Guards($settings);
        $this->locks = new Locks($storage);
        $this->runner = $runner;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = self::fromWordPress();
        }
        return self::$instance;
    }

    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    public static function fromWordPress(): self
    {
        global $wpdb;
        $connection = new WpdbConnection($wpdb);
        $installer = Installer::forWordPress($connection);
        $plugin = new self(new SqlStorage($connection), new Registry(), null, null, null, static fn (): bool => $installer->install());
        $plugin->installerObject = $installer;
        return $plugin;
    }

    public function storage(): Storage
    {
        return $this->storage;
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    public function settings(bool $fresh = false): Settings
    {
        return ($this->settingsLoader)($fresh);
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function locks(): Locks
    {
        return $this->locks;
    }

    public function nextRun(): NextRun
    {
        return $this->nextRun;
    }

    public function installerObject(): ?Installer
    {
        return $this->installerObject;
    }

    public function runner(): Runner
    {
        if ($this->runner === null) {
            $this->runner = new Runner($this->storage, $this->locks, $this->settingsLoader, $this->clock, $this->nextRun);
        }
        return $this->runner;
    }

    public function tick(): Tick
    {
        return new Tick(
            $this->storage,
            $this->registry,
            $this->settingsLoader,
            $this->locks,
            $this->runner(),
            $this->nextRun,
            $this->guards,
            $this->memory,
            $this->clock,
            $this->installer
        );
    }

    public function guards(Settings $settings): Guards
    {
        return ($this->guards)($settings);
    }

    public function operator(): Operator
    {
        return new Operator($this->storage, $this->registry, $this->settingsLoader, $this->saveSettings, $this->nextRun);
    }

    /**
     * Create the tables when they are missing (must-use installs never see
     * the activation hook).
     */
    public function install(): bool
    {
        return $this->installer !== null && ($this->installer)();
    }

    /**
     * Hook everything into WordPress. Called once from the main plugin file.
     */
    public function boot(string $file): void
    {
        add_action('init', [$this, 'fireRegister'], self::REGISTER_PRIORITY);
        $fallback = new Fallback($this);
        add_filter('cron_schedules', [$fallback, 'addSchedule']);
        add_action(Fallback::HOOK, [$fallback, 'handle']);
        if (function_exists('register_activation_hook')) {
            register_activation_hook($file, [$this, 'activate']);
            register_deactivation_hook($file, [Installer::class, 'deactivate']);
        }
        if (is_admin()) {
            $screen = new Screen($this);
            add_action('admin_menu', [$screen, 'addPage']);
            add_action('admin_post_' . Screen::ACTION, [$screen, 'handlePost']);
            add_action('admin_init', function () use ($fallback): void {
                if ($this->installerObject !== null && current_user_can('activate_plugins')) {
                    $this->installerObject->maybeUpgrade();
                }
                try {
                    $lanes = $this->settings()->bool('wp_cron_fallback')
                        ? $this->operator()->lanes($this->operator()->rules())
                        : [];
                    $fallback->reconcile($lanes);
                } catch (Exception\StorageException $e) {
                    Log::error('could not reconcile WP-Cron fallback events: ' . $e->getMessage());
                }
            });
        }
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('import-scheduler', Cli\Command::class);
        }
    }

    public function activate(): void
    {
        $this->install();
    }

    /**
     * Fires the register action. Registrations queued before it become
     * visible; registrations during and after it apply at once.
     */
    public function fireRegister(): void
    {
        if ($this->registry->isOpen()) {
            return;
        }
        $this->registry->open();
        do_action('import_scheduler_register');
    }
}
