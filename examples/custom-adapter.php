<?php

/**
 * Plugin Name: Import Scheduler example: a custom adapter
 * Description: Wraps a JSON endpoint as an import rule. Shows timeouts, shouldStop() and heartbeat().
 */

declare(strict_types=1);

namespace ExampleImports;

use IndianOpinion\ImportScheduler\Adapter;
use IndianOpinion\ImportScheduler\DescribesItself;
use IndianOpinion\ImportScheduler\RunContext;
use IndianOpinion\ImportScheduler\RunResult;

if (!defined('ABSPATH')) {
    exit;
}

final class JsonAdapter implements Adapter, DescribesItself
{
    public function __construct(private string $endpoint, private string $source)
    {
    }

    public function label(): string
    {
        return 'JSON: ' . $this->source;
    }

    /**
     * @return RunResult
     */
    public function run(RunContext $context)
    {
        // Every network call gets a timeout. A call without one can hold the
        // rule lock until somebody notices.
        $response = wp_safe_remote_get($this->endpoint, ['timeout' => 15]);
        if (is_wp_error($response)) {
            return RunResult::failure($response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return RunResult::failure('HTTP ' . $code);
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return RunResult::failure('unexpected response shape');
        }
        $emitted = 0;
        foreach ($data['items'] as $i => $row) {
            if ($context->shouldStop()) {
                $context->log('info', 'stopped early after ' . $emitted . ' items');
                break;
            }
            if ($i % 50 === 0 && !$context->heartbeat()) {
                return RunResult::failure('lost the rule lock');
            }
            if (!is_array($row) || !isset($row['url'], $row['title'])) {
                continue;
            }
            $item = [
                'url' => (string) $row['url'],
                'title' => sanitize_text_field((string) $row['title']),
                'body' => wp_kses_post((string) ($row['body'] ?? '')),
                'source_name' => $this->source,
                'published' => isset($row['published']) ? (int) $row['published'] : null,
                'key' => (string) ($row['id'] ?? $row['url']),
                'feed_url' => $this->endpoint,
                'rule_id' => $context->ruleId(),
            ];
            do_action('import_scheduler_item', $item, $context->ruleId(), $context);
            $emitted++;
        }
        return RunResult::success($emitted, sprintf('emitted %d', $emitted));
    }
}

add_action('import_scheduler_register', static function (): void {
    import_scheduler_register(
        'example-json',
        new JsonAdapter('https://example.net/api/items.json', 'Example JSON'),
        ['interval' => 900, 'lane' => 'apis', 'max_runtime' => 120]
    );
});
