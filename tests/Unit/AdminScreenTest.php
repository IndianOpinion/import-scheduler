<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Admin\Screen;
use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\RunResult;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class AdminScreenTest extends TestCase
{
    private function screen(): Screen
    {
        return new Screen($this->plugin);
    }

    /** T42 (4.9.1, 4.9.5, section 8) */
    public function testEveryActionRejectsAMissingNonceAndAUserWithoutTheCapability(): void
    {
        $this->rule('r');
        $screen = $this->screen();
        $screen->addPage();
        $this->assertSame('manage_options', $GLOBALS['wp_stub']['pages']['import-scheduler']);
        foreach (Screen::ACTIONS as $action) {
            $post = ['do' => $action, 'id' => 'r', 'lane' => 'default', 'name' => 'lane:default:1', 'seconds' => '600'];
            try {
                $screen->process($post);
                $this->fail('missing nonce accepted for ' . $action);
            } catch (\WpStubDie $e) {
                $this->assertSame(403, $e->status);
            }
            try {
                $screen->process($post + ['_wpnonce' => 'forged']);
                $this->fail('bad nonce accepted for ' . $action);
            } catch (\WpStubDie $e) {
                $this->assertSame(403, $e->status);
            }
            $GLOBALS['wp_stub']['caps'] = [];
            try {
                $screen->process($post + ['_wpnonce' => 'valid-nonce']);
                $this->fail('no capability accepted for ' . $action);
            } catch (\WpStubDie $e) {
                $this->assertSame(403, $e->status);
            }
            $GLOBALS['wp_stub']['caps'] = ['manage_options' => true];
        }
        $this->assertSame([], $this->db->rules, 'nothing changed');
        $GLOBALS['wp_stub']['caps'] = [];
        $this->expectException(\WpStubDie::class);
        $screen->render([]);
    }

    /** T42 (4.9.1): the capability is filterable. */
    public function testTheCapabilityIsFilterable(): void
    {
        add_filter('import_scheduler_capability', static fn () => 'edit_posts');
        $GLOBALS['wp_stub']['caps'] = ['edit_posts' => true];
        $this->rule('r');
        $url = $this->screen()->process(['do' => 'run_now', 'id' => 'r', '_wpnonce' => 'valid-nonce']);
        $this->assertStringContainsString('page=import-scheduler', $url);
    }

    /** T43 (4.9.2, 4.15.6) */
    public function testALaneWithNoRecentTickShowsTheWarningAndTheCronLine(): void
    {
        $this->rule('a');
        $this->rule('b', 0, null, ['lane' => 'slow']);
        $this->tick();
        $html = $this->screen()->render([]);
        $this->assertStringContainsString('Lane slow: no tick seen since the plugin was installed. Check the system cron line.', $html);
        $this->assertStringContainsString('wp import-scheduler tick --lane=slow --quiet', $html);
        $this->assertStringContainsString('cd /var/www/example', $html);
        $this->assertStringNotContainsString('Lane default: no tick seen', $html);
        $this->clock->advance(181);
        $html = $this->screen()->render([]);
        $this->assertStringContainsString('Lane default: no tick seen since', $html);
        $this->assertStringContainsString('<td>ran 1</td>', $html, 'last tick outcome');
    }

    /** T44 (4.9.3, 4.9.6, 4.9.8, 4.9.4, 4.9.9, 4.9.10) */
    public function testRulesTableHistoryTimesFiltersAndColumns(): void
    {
        $GLOBALS['wp_stub']['tz_offset'] = 19800;
        $this->rule('alpha', 2, RunResult::success(5, 'emitted 5'), ['label' => 'Alpha feed', 'interval' => 600]);
        $this->rule('beta', 1, false, ['lane' => 'slow']);
        $this->tick();
        $this->tick('slow');
        $this->plugin->operator()->setInterval('alpha', 900);
        $start = (int) $this->runs('alpha')[0]['started'];

        $html = $this->screen()->render([]);
        foreach (['Rule', 'Lane', 'Interval', 'Status', 'Last run', 'Last duration', 'Last result', 'Next run', 'Failures', 'Median (20)'] as $heading) {
            $this->assertStringContainsString('>' . $heading . '</a></th>', $html);
        }
        $this->assertStringContainsString('Alpha feed', $html);
        $this->assertStringContainsString('(overridden)', $html);
        $this->assertStringContainsString('2.0s', $html);
        $this->assertStringContainsString('success, 5 items', $html);
        $this->assertStringContainsString('failing', $html);
        $this->assertStringContainsString(gmdate('Y-m-d H:i:s', $start + 19800), $html, 'site time zone');
        $this->assertStringContainsString('title="' . gmdate('Y-m-d H:i:s', $start) . ' UTC"', $html, 'UTC on hover');
        $utc = $this->screen()->render(['utc' => '1']);
        $this->assertStringContainsString('<small>' . gmdate('Y-m-d H:i:s', $start) . ' UTC</small>', $utc);

        $filtered = $this->screen()->render(['lane' => 'slow']);
        $this->assertStringNotContainsString('Alpha feed', $filtered);
        $this->assertStringContainsString('<code>beta</code>', $filtered);
        $this->assertStringNotContainsString('<code>beta</code>', $this->screen()->render(['status' => 'idle']));
        $this->assertStringNotContainsString('<code>beta</code>', $this->screen()->render(['s' => 'alph']));
        $sorted = $this->screen()->render(['orderby' => 'label', 'order' => 'desc']);
        $this->assertLessThan(strpos($sorted, '<code>alpha</code>'), strpos($sorted, '<code>beta</code>'));

        $GLOBALS['wp_stub']['hidden_columns'] = ['median', 'failures'];
        $hidden = $this->screen()->render([]);
        $this->assertStringNotContainsString('Median (20)', $hidden);
        $this->assertStringNotContainsString('column-failures', $hidden);
        $this->assertArrayHasKey('median', $this->screen()->columns());

        $history = $this->screen()->render(['history' => 'alpha']);
        $this->assertStringContainsString('History: Alpha feed (alpha)', $history);
        $this->assertStringContainsString('emitted 5', $history);
        foreach (['Start', 'Duration', 'Status', 'Items', 'Message'] as $heading) {
            $this->assertStringContainsString('<th>' . $heading . '</th>', $history);
        }

        for ($i = 0; $i < 120; $i++) {
            $this->registry->register(sprintf('bulk-%03d', $i), static fn () => null, ['interval' => 300]);
        }
        $page1 = $this->screen()->render([]);
        $this->assertSame(100, substr_count($page1, '<code>'), 'paginated at 100 rows');
        $this->assertStringContainsString('paged=2', $page1);

        $this->assertFalse(import_scheduler_register('Bad Id', static fn () => null, ['interval' => 60]));
        $this->assertStringContainsString('Registration problems', $this->screen()->render([]));
    }

    /** T45 (4.9.7) */
    public function testRunNowOnTheScreenRunsNothingInTheRequest(): void
    {
        $ran = 0;
        $this->registry->register('r', static function () use (&$ran): void {
            $ran++;
        }, ['interval' => 3600]);
        $this->tick();
        $this->assertSame(1, $ran);
        $url = $this->screen()->process(['do' => 'run_now', 'id' => 'r', '_wpnonce' => 'valid-nonce']);
        $this->assertSame(1, $ran);
        $this->assertCount(1, $this->runs('r'));
        $this->assertSame($this->db->now(), $this->state('r')['next_run']);
        $this->assertStringContainsString('tools.php?page=import-scheduler', $url);
        $this->assertStringContainsString('r is due', $this->screen()->render([]));

        // The other actions work through the same path.
        $screen = $this->screen();
        $screen->process(['do' => 'pause', 'id' => 'r', '_wpnonce' => 'valid-nonce']);
        $this->assertSame(1, $this->state('r')['paused']);
        $screen->process(['do' => 'resume', 'id' => 'r', '_wpnonce' => 'valid-nonce']);
        $screen->process(['do' => 'interval', 'id' => 'r', 'seconds' => '600', '_wpnonce' => 'valid-nonce']);
        $this->assertSame(600, $this->state('r')['interval_override']);
        $screen->process(['do' => 'interval', 'id' => 'r', 'seconds' => '5', '_wpnonce' => 'valid-nonce']);
        $this->assertStringContainsString('outside 60 to 2592000', $this->screen()->render([]));
        $screen->process(['do' => 'reset_interval', 'id' => 'r', '_wpnonce' => 'valid-nonce']);
        $this->assertNull($this->state('r')['interval_override']);
        $screen->process(['do' => 'lane', 'id' => 'r', 'lane' => 'other', '_wpnonce' => 'valid-nonce']);
        $this->assertSame('other', $this->state('r')['lane']);
        $screen->process(['do' => 'pause_lane', 'lane' => 'other', '_wpnonce' => 'valid-nonce']);
        $this->assertTrue($this->plugin->settings()->isLanePaused('other'));
        $screen->process(['do' => 'resume_lane', 'lane' => 'other', '_wpnonce' => 'valid-nonce']);
        $this->assertFalse($this->plugin->settings()->isLanePaused('other'));
        $url = $screen->process(['do' => 'save_settings', '_wpnonce' => 'valid-nonce', 'settings' => ['enabled' => '1', 'tick_budget' => '99999', 'history_keep' => '20', 'lane_concurrency' => "default=2\nother=3"]]);
        $this->assertStringContainsString('tab=settings', $url);
        $this->assertSame(55, $this->plugin->settings()->int('tick_budget'), 'invalid value rejected, previous kept');
        $this->assertSame(20, $this->plugin->settings()->int('history_keep'));
        $this->assertSame(3, $this->plugin->settings()->laneConcurrency('other'));
        $this->assertStringContainsString('tick_budget must be between 5 and 3600', $this->screen()->render(['tab' => 'settings']));

        $owner = Locks::newOwner();
        $this->storage->insertLock('lane:default:1', $owner, 600);
        $url = $screen->process(['do' => 'unlock', 'name' => 'lane:default:1', '_wpnonce' => 'valid-nonce']);
        $this->assertStringContainsString('confirm_unlock=lane%3Adefault%3A1', $url);
        $this->assertArrayHasKey('lane:default:1', $this->db->locks, 'asks before removing a held lock');
        $this->assertStringContainsString('Remove it anyway?', $this->screen()->render(['confirm_unlock' => 'lane:default:1']));
        $screen->process(['do' => 'unlock', 'name' => 'lane:default:1', 'force' => '1', '_wpnonce' => 'valid-nonce']);
        $this->assertArrayNotHasKey('lane:default:1', $this->db->locks);
    }

    /** T64 (section 8) */
    public function testRunMessagesWithMarkupAreEscaped(): void
    {
        $evil = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $this->rule('xss', 0, RunResult::failure($evil), ['label' => '<b>label</b>']);
        $this->registry->register('xss2', function (\IndianOpinion\ImportScheduler\RunContext $c) use ($evil) {
            $c->log('warn"><script>', $evil);
            return RunResult::success(1, $evil);
        }, ['interval' => 300]);
        $this->tick();
        $html = $this->screen()->render([]) . $this->screen()->render(['history' => 'xss2']) . $this->screen()->render(['history' => 'xss']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>label', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
