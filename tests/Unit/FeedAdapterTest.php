<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Feed\FeedAdapter;
use IndianOpinion\ImportScheduler\RunContext;
use IndianOpinion\ImportScheduler\Tests\Support\FakeFeed;
use IndianOpinion\ImportScheduler\Tests\Support\FakeItem;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class FeedAdapterTest extends TestCase
{
    /** @var list<array{0: array<string, mixed>, 1: string}> */
    private array $emitted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->emitted = [];
        add_action('import_scheduler_item', function (array $item, string $ruleId, RunContext $context): void {
            $this->emitted[] = [$item, $ruleId];
        }, 10, 3);
    }

    /**
     * @param list<FakeItem> $items
     */
    private function serve(array $items, ?string $title = 'Example Feed'): FakeFeed
    {
        $feed = new FakeFeed($items, $title);
        $GLOBALS['wp_stub']['feed'] = static function (string $url) use ($feed) {
            do_action_ref_array('wp_feed_options', [&$feed, $url]);
            return $feed;
        };
        return $feed;
    }

    /** T52 (4.14.2, 4.14.1) */
    public function testNonHttpFeedUrlsAreRejected(): void
    {
        foreach (['ftp://example.com/feed', '/feed.xml', 'javascript:alert(1)', 'feed.example.com/rss', '', 'http://', 'file:///etc/passwd'] as $bad) {
            $this->assertFalse(import_scheduler_register_feed('f' . md5($bad), $bad, ['interval' => 600]), $bad);
        }
        $this->assertTrue(import_scheduler_register_feed('ok', 'https://example.com/feed', ['interval' => 600, 'lane' => 'feeds', 'max_items' => 20, 'source_name' => 'Example']));
        $rule = $this->registry->get('ok');
        $this->assertNotNull($rule);
        $this->assertSame('feeds', $rule->lane);
        $this->assertInstanceOf(FeedAdapter::class, $rule->runner());
        $this->assertSame('https://example.com/feed', $rule->label, 'label falls back to the feed URL');
        $this->assertFalse(import_scheduler_register_feed('toomany', 'https://example.com/feed', ['interval' => 600, 'max_items' => 501]));
        $this->assertFalse(import_scheduler_register_feed('notime', 'https://example.com/feed', ['interval' => 600, 'timeout' => 0]));
        $this->assertFalse(import_scheduler_register_feed('noint', 'https://example.com/feed', []));
    }

    /** T53 (4.14.3) */
    public function testTheCacheLifetimeFilterIsSetForTheCallAndRemovedAfter(): void
    {
        $seen = null;
        $feed = new FakeFeed([]);
        $GLOBALS['wp_stub']['feed'] = static function () use (&$seen, $feed) {
            $seen = apply_filters('wp_feed_cache_transient_lifetime', 43200, 'https://example.com/feed');
            return $feed;
        };
        import_scheduler_register_feed('f', 'https://example.com/feed', ['interval' => 1800]);
        import_scheduler_register_feed('g', 'https://example.com/other', ['interval' => 60]);
        $this->tick();
        $this->assertSame(60, $seen, 'never below 60 seconds');
        $this->plugin->operator()->markDue('f');
        $this->tick();
        $this->assertSame(900, $seen, 'half the interval');
        $this->assertSame(43200, apply_filters('wp_feed_cache_transient_lifetime', 43200, 'x'), 'removed afterwards');
        $this->assertFalse(has_filter('wp_feed_options'));
    }

    /** T54 (4.14.4) */
    public function testAFeedErrorFailsTheRun(): void
    {
        $GLOBALS['wp_stub']['feed'] = static fn () => new \WP_Error('simplepie-error', 'cURL error 28: Operation timed out');
        import_scheduler_register_feed('f', 'https://example.com/feed', ['interval' => 600]);
        $this->tick();
        $run = $this->runs('f')[0];
        $this->assertSame('failure', $run['status']);
        $this->assertSame('feed error: cURL error 28: Operation timed out', $run['message']);
    }

    /** T55 (4.14.5) */
    public function testItemNormalisation(): void
    {
        $this->serve([
            new FakeItem('https://example.com/a', '  Rock &amp; <b>Roll</b>  news ', '<p>Body</p><script>bad()</script>', 'desc', 1_700_000_000, 'tag:example.com,2026:a'),
            new FakeItem('https://example.com/b', 'Only description', null, '<em>summary</em>', null, null),
            new FakeItem('https://example.com/c', 'Nothing', '', '', null, ''),
            new FakeItem('/relative/d', 'Relative link'),
            new FakeItem('mailto:x@example.com', 'Mail link'),
            new FakeItem(null, 'No link'),
            new FakeItem('https://example.com/e', '  <i></i> '),
        ], 'The &amp; Feed');
        import_scheduler_register_feed('f', 'https://feeds.example.com/rss', ['interval' => 600]);
        $this->tick();
        $this->assertCount(3, $this->emitted);
        [$first, $ruleId] = $this->emitted[0];
        $this->assertSame('f', $ruleId);
        $this->assertSame([
            'url' => 'https://example.com/a',
            'title' => 'Rock & Roll news',
            'body' => '<p>Body</p>',
            'source_name' => 'The & Feed',
            'published' => 1_700_000_000,
            'key' => 'tag:example.com,2026:a',
            'feed_url' => 'https://feeds.example.com/rss',
            'rule_id' => 'f',
        ], $first);
        $second = $this->emitted[1][0];
        $this->assertSame('<em>summary</em>', $second['body'], 'description when there is no content');
        $this->assertNull($second['published']);
        $this->assertSame('https://example.com/b', $second['key'], 'URL when there is no id');
        $this->assertSame('', $this->emitted[2][0]['body']);
        $run = $this->runs('f')[0];
        $this->assertSame(3, $run['items']);
        $this->assertSame('emitted 3, skipped 4', $run['message']);

        // Source name: argument first, then feed title, then host.
        $this->serve([new FakeItem('https://example.com/a', 'A')], null);
        import_scheduler_register_feed('host', 'https://news.example.org/feed', ['interval' => 600]);
        import_scheduler_register_feed('named', 'https://news.example.org/feed2', ['interval' => 600, 'source_name' => 'Named Source']);
        $this->emitted = [];
        $this->tick();
        $sources = array_map(static fn (array $e): string => $e[0]['source_name'], $this->emitted);
        $this->assertSame(['news.example.org', 'Named Source'], $sources);
    }

    /** T56 (4.14.6) */
    public function testSinceLastSuccessDropsOlderItemsAndKeepsUndatedOnes(): void
    {
        $now = $this->db->now();
        $this->serve([
            new FakeItem('https://example.com/new', 'New', null, null, $now + 10),
            new FakeItem('https://example.com/old', 'Old', null, null, $now - 10),
            new FakeItem('https://example.com/undated', 'Undated'),
        ]);
        import_scheduler_register_feed('f', 'https://example.com/feed', ['interval' => 600, 'since_last_success' => true]);
        $this->tick();
        $this->assertCount(3, $this->emitted, 'no previous success: everything');
        $this->emitted = [];
        $this->plugin->operator()->markDue('f');
        $this->tick();
        $this->assertSame(['https://example.com/new', 'https://example.com/undated'], array_map(static fn (array $e): string => $e[0]['url'], $this->emitted));
        $this->assertSame('emitted 2, skipped 1', $this->runs('f')[1]['message']);
    }

    /** T57 (4.14.7) */
    public function testTheFilterCanDropAnItem(): void
    {
        $this->serve([new FakeItem('https://example.com/a', 'Keep'), new FakeItem('https://example.com/b', 'Drop me')]);
        add_filter('import_scheduler_feed_item', static fn ($item, $ruleId) => $item['title'] === 'Drop me' ? false : $item + ['tag' => $ruleId], 10, 2);
        import_scheduler_register_feed('f', 'https://example.com/feed', ['interval' => 600]);
        $this->tick();
        $this->assertCount(1, $this->emitted);
        $this->assertSame('Keep', $this->emitted[0][0]['title']);
        $this->assertSame('f', $this->emitted[0][0]['tag']);
        $this->assertSame('emitted 1, skipped 1', $this->runs('f')[0]['message']);
    }

    /** T58 (4.14.8) */
    public function testTheAdapterStopsEarlyWhenAskedTo(): void
    {
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = new FakeItem('https://example.com/' . $i, 'Item ' . $i);
        }
        $this->serve($items);
        add_action('import_scheduler_item', function (array $item): void {
            if ($item['title'] === 'Item 2') {
                $this->plugin->operator()->requestStop('f');
            }
        });
        import_scheduler_register_feed('f', 'https://example.com/feed', ['interval' => 600]);
        $this->tick();
        $this->assertCount(3, $this->emitted);
        $run = $this->runs('f')[0];
        $this->assertSame('success', $run['status'], 'recorded normally');
        $this->assertSame(3, $run['items']);
        $this->assertStringContainsString('stopped early', (string) $run['log']);
    }

    /** T59 (4.14.9, 4.14.10) */
    public function testResultCountsAndAConsumerExceptionFailsTheRun(): void
    {
        $this->serve([new FakeItem('https://example.com/a', 'A'), new FakeItem('https://example.com/b', 'B'), new FakeItem('https://example.com/c', 'C')]);
        import_scheduler_register_feed('f', 'https://example.com/feed', ['interval' => 600, 'max_items' => 2]);
        $this->tick();
        $this->assertSame('emitted 2, skipped 0', $this->runs('f')[0]['message'], 'max_items caps the read');

        add_action('import_scheduler_item', static function (array $item): void {
            if ($item['title'] === 'B') {
                throw new \RuntimeException('could not save post');
            }
        });
        $this->emitted = [];
        $this->plugin->operator()->markDue('f');
        $this->tick();
        $run = $this->runs('f')[1];
        $this->assertSame('failure', $run['status']);
        $this->assertSame('RuntimeException: could not save post', $run['message']);
        $this->assertCount(2, $this->emitted, 'items before the failure stay emitted');
    }

    /** T66 (4.8.7) */
    public function testTheReadmeAsksForTimeoutsAndTheAdapterSetsOne(): void
    {
        $feed = $this->serve([]);
        import_scheduler_register_feed('f', 'https://example.com/feed', ['interval' => 600]);
        import_scheduler_register_feed('g', 'https://example.com/slow', ['interval' => 600, 'timeout' => 40, 'lane' => 'slow']);
        $this->tick();
        $this->assertSame(15, $feed->timeout, 'default timeout on the fetch');
        $this->tick('slow');
        $this->assertSame(40, $feed->timeout);

        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $this->assertMatchesRegularExpression('/Give every (HTTP|network) call in a runner a timeout/i', $readme);
    }
}
