<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Support;

/**
 * Just enough of a feed object for the reference adapter.
 */
final class FakeFeed
{
    public ?int $timeout = null;

    /**
     * @param list<FakeItem> $items
     */
    public function __construct(private array $items, private ?string $title = 'Example Feed')
    {
    }

    public function set_timeout(int $timeout): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        $this->timeout = $timeout;
    }

    public function get_title(): ?string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        return $this->title;
    }

    /**
     * @return list<FakeItem>
     */
    public function get_items(int $start = 0, int $quantity = 0): array // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        return array_slice($this->items, $start, $quantity > 0 ? $quantity : null);
    }
}
