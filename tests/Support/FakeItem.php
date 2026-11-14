<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Support;

/**
 * Just enough of a feed item for the reference adapter.
 */
final class FakeItem
{
    public function __construct(
        private ?string $permalink,
        private ?string $title,
        private ?string $content = null,
        private ?string $description = null,
        private ?int $date = null,
        private ?string $id = null
    ) {
    }

    public function get_permalink(): ?string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        return $this->permalink;
    }

    public function get_title(): ?string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        return $this->title;
    }

    public function get_content(): ?string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        return $this->content;
    }

    public function get_description(): ?string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        return $this->description;
    }

    public function get_date(string $format = 'j F Y, g:i a'): ?int // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        return $this->date;
    }

    public function get_id(bool $hash = false): ?string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        return $this->id;
    }
}
