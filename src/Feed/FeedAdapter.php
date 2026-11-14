<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Feed;

use IndianOpinion\ImportScheduler\Adapter;
use IndianOpinion\ImportScheduler\DescribesItself;
use IndianOpinion\ImportScheduler\Exception\InvalidRuleException;
use IndianOpinion\ImportScheduler\RunContext;
use IndianOpinion\ImportScheduler\RunResult;

/**
 * Reference adapter: one feed URL through WordPress core's fetch_feed().
 *
 * Each item is normalised and emitted through the import_scheduler_item
 * action. Creating posts is the consumer's job. The adapter keeps no
 * seen-item store; every item carries a stable key for de-duplication.
 */
final class FeedAdapter implements Adapter, DescribesItself
{
    public const MAX_ITEMS = 500;
    public const DEFAULT_MAX_ITEMS = 50;
    public const DEFAULT_TIMEOUT = 15;

    private int $maxItems;
    private int $timeout;
    private string $sourceName;
    private bool $sinceLastSuccess;

    /**
     * @param array{source_name?: mixed, max_items?: mixed, timeout?: mixed, since_last_success?: mixed} $options
     * @throws InvalidRuleException
     */
    public function __construct(private string $feedUrl, array $options = [], private string $label = '')
    {
        if (!self::isValidFeedUrl($feedUrl)) {
            throw new InvalidRuleException(sprintf('Feed URL "%s" must be an absolute http or https URL.', $feedUrl));
        }
        $this->maxItems = self::intOption($options, 'max_items', self::DEFAULT_MAX_ITEMS, 1, self::MAX_ITEMS);
        $this->timeout = self::intOption($options, 'timeout', self::DEFAULT_TIMEOUT, 1, 300);
        $source = $options['source_name'] ?? '';
        if (!is_string($source)) {
            throw new InvalidRuleException('source_name must be a string.');
        }
        $this->sourceName = trim($source);
        $this->sinceLastSuccess = (bool) ($options['since_last_success'] ?? false);
    }

    public static function isValidFeedUrl(string $url): bool
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return false;
        }
        return function_exists('wp_http_validate_url') ? wp_http_validate_url($url) !== false : true;
    }

    public function label(): string
    {
        return $this->label !== '' ? $this->label : $this->feedUrl;
    }

    public function feedUrl(): string
    {
        return $this->feedUrl;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * @return RunResult
     */
    public function run(RunContext $context)
    {
        $lifetime = max(60, intdiv(max(1, $context->interval()), 2));
        $cacheFilter = static fn (): int => $lifetime;
        $timeout = $this->timeout;
        $feedUrl = $this->feedUrl;
        $options = static function ($feed, $url = '') use ($timeout, $feedUrl): void {
            if (is_object($feed) && method_exists($feed, 'set_timeout') && ($url === '' || $url === $feedUrl)) {
                $feed->set_timeout($timeout);
            }
        };
        add_filter('wp_feed_cache_transient_lifetime', $cacheFilter, 999);
        add_action('wp_feed_options', $options, 10, 2);
        try {
            $feed = fetch_feed($this->feedUrl);
        } finally {
            remove_filter('wp_feed_cache_transient_lifetime', $cacheFilter, 999);
            remove_action('wp_feed_options', $options, 10);
        }
        if (is_wp_error($feed)) {
            return RunResult::failure('feed error: ' . $feed->get_error_message());
        }
        if (!is_object($feed) || !method_exists($feed, 'get_items')) {
            return RunResult::failure('feed error: fetch_feed() returned no feed');
        }

        $source = $this->sourceName;
        if ($source === '' && method_exists($feed, 'get_title')) {
            $title = $feed->get_title();
            $source = is_string($title) ? self::cleanText($title) : '';
        }
        if ($source === '') {
            $host = parse_url($this->feedUrl, PHP_URL_HOST);
            $source = is_string($host) ? $host : '';
        }

        $since = $this->sinceLastSuccess ? $context->lastSuccessAt() : null;
        $items = $feed->get_items(0, $this->maxItems);
        $emitted = 0;
        $skipped = 0;
        foreach (is_array($items) ? array_slice($items, 0, $this->maxItems) : [] as $raw) {
            if ($context->shouldStop()) {
                $context->log('info', 'stopped early on request');
                break;
            }
            $item = is_object($raw) ? $this->normalise($raw, $source, $context->ruleId()) : null;
            if ($item === null) {
                $skipped++;
                continue;
            }
            if ($since !== null && $item['published'] !== null && $item['published'] <= $since) {
                $skipped++;
                continue;
            }
            $filtered = apply_filters('import_scheduler_feed_item', $item, $context->ruleId());
            if ($filtered === false || !is_array($filtered)) {
                $skipped++;
                continue;
            }
            do_action('import_scheduler_item', $filtered, $context->ruleId(), $context);
            $emitted++;
        }
        return RunResult::success($emitted, sprintf('emitted %d, skipped %d', $emitted, $skipped));
    }

    /**
     * @return array{url: string, title: string, body: string, source_name: string, published: ?int, key: string, feed_url: string, rule_id: string}|null
     */
    public function normalise(object $raw, string $source, string $ruleId): ?array
    {
        $url = method_exists($raw, 'get_permalink') ? $raw->get_permalink() : null;
        $url = is_string($url) ? trim($url) : '';
        if (preg_match('#^https?://[^\s/]+#i', $url) !== 1) {
            return null;
        }
        $title = method_exists($raw, 'get_title') ? $raw->get_title() : '';
        $title = self::cleanText(is_string($title) ? $title : '');
        if ($title === '') {
            return null;
        }
        $body = method_exists($raw, 'get_content') ? $raw->get_content() : null;
        if (!is_string($body) || trim($body) === '') {
            $body = method_exists($raw, 'get_description') ? $raw->get_description() : '';
        }
        $body = wp_kses_post(is_string($body) ? $body : '');
        $published = null;
        if (method_exists($raw, 'get_date')) {
            $date = $raw->get_date('U');
            if (is_numeric($date) && (int) $date > 0) {
                $published = (int) $date;
            }
        }
        $key = method_exists($raw, 'get_id') ? $raw->get_id(false) : null;
        $key = is_string($key) && trim($key) !== '' ? trim($key) : $url;
        return [
            'url' => $url,
            'title' => $title,
            'body' => $body,
            'source_name' => $source,
            'published' => $published,
            'key' => $key,
            'feed_url' => $this->feedUrl,
            'rule_id' => $ruleId,
        ];
    }

    /**
     * Decode entities, strip tags, collapse whitespace.
     */
    public static function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = wp_strip_all_tags($text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function intOption(array $options, string $key, int $default, int $min, int $max): int
    {
        if (!isset($options[$key])) {
            return $default;
        }
        $value = $options[$key];
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidRuleException(sprintf('%s must be a whole number.', $key));
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new InvalidRuleException(sprintf('%s must be between %d and %d.', $key, $min, $max));
        }
        return $value;
    }
}
