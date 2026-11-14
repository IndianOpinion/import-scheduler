<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

use IndianOpinion\ImportScheduler\Exception\InvalidRuleException;

/**
 * Rules registered in this request.
 *
 * Registration before the register action fires is validated at once and
 * queued; it becomes visible when the action fires. After that, registration
 * applies at once. The first registration of an id wins.
 */
final class Registry
{
    /** @var array<string, Rule> */
    private array $rules = [];

    /** @var array<string, Rule> */
    private array $queued = [];

    /** @var list<string> */
    private array $problems = [];

    private bool $open = false;

    /**
     * @param callable|Adapter|mixed $runner
     * @param array<string, mixed>   $args
     * @throws InvalidRuleException
     */
    public function register(string $id, $runner, array $args = []): Rule
    {
        try {
            $rule = Rule::fromArgs($id, $runner, $args);
            if (isset($this->rules[$id]) || isset($this->queued[$id])) {
                throw new InvalidRuleException(sprintf('Rule "%s" is already registered; the first registration wins.', $id));
            }
        } catch (InvalidRuleException $e) {
            $this->problems[] = $e->getMessage();
            throw $e;
        }
        if ($this->open) {
            $this->rules[$id] = $rule;
        } else {
            $this->queued[$id] = $rule;
        }
        return $rule;
    }

    /**
     * Called when the register action fires: queued rules become visible and
     * later registrations apply at once.
     */
    public function open(): void
    {
        $this->open = true;
        foreach ($this->queued as $id => $rule) {
            $this->rules[$id] = $rule;
        }
        $this->queued = [];
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function get(string $id): ?Rule
    {
        return $this->rules[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->rules[$id]);
    }

    /**
     * @return array<string, Rule>
     */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * @return list<string> Rejected registrations in this request.
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
