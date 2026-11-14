<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * What a runner reports back: a status, an item count and a message.
 */
final class RunResult
{
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';

    private function __construct(
        private string $status,
        private ?int $items,
        private string $message
    ) {
    }

    public static function success(int $items = 0, string $message = ''): self
    {
        return new self(self::SUCCESS, max(0, $items), $message);
    }

    public static function failure(string $message): self
    {
        return new self(self::FAILURE, null, $message);
    }

    /**
     * Success with an unknown item count.
     */
    public static function successUnknownCount(string $message = ''): self
    {
        return new self(self::SUCCESS, null, $message);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function items(): ?int
    {
        return $this->items;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function withMessage(string $message): self
    {
        return new self($this->status, $this->items, $message);
    }
}
