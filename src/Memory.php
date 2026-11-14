<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

/**
 * Memory use against memory_limit.
 */
final class Memory
{
    /** @var callable(): int */
    private $usage;

    /** @var callable(): string */
    private $limit;

    /**
     * @param (callable(): int)|null    $usage
     * @param (callable(): string)|null $limit
     */
    public function __construct(?callable $usage = null, ?callable $limit = null)
    {
        $this->usage = $usage ?? static fn (): int => memory_get_usage(true);
        $this->limit = $limit ?? static fn (): string => (string) ini_get('memory_limit');
    }

    public function aboveCeiling(int $percent): bool
    {
        $limit = self::toBytes(($this->limit)());
        if ($limit <= 0) {
            return false;
        }
        return ($this->usage)() > $limit * $percent / 100;
    }

    public static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        if (preg_match('/^(\d+)\s*([kmg]?)$/i', $value, $m) !== 1) {
            return -1;
        }
        $n = (int) $m[1];
        switch (strtolower($m[2])) {
            case 'g':
                return $n * 1073741824;
            case 'm':
                return $n * 1048576;
            case 'k':
                return $n * 1024;
            default:
                return $n;
        }
    }
}
