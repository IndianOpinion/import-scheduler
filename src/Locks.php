<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler;

use IndianOpinion\ImportScheduler\Exception\LockException;
use IndianOpinion\ImportScheduler\Storage\Storage;

/**
 * Database locks with an owner and an expiry.
 *
 * Take inserts the row; if the name is taken, a single conditional update
 * takes it over only when it has expired. Release and renew match the owner,
 * so nobody frees a lock they do not hold. An expired lock is free: a crashed
 * process never wedges a lane for longer than the expiry.
 */
final class Locks
{
    public function __construct(private Storage $storage)
    {
    }

    public static function newOwner(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function laneSlot(string $lane, int $slot): string
    {
        return sprintf('lane:%s:%d', $lane, $slot);
    }

    public static function rule(string $ruleId): string
    {
        return 'rule:' . $ruleId;
    }

    /** Lane slot expiry: tick budget plus the largest max runtime, plus grace. */
    public static function laneTtl(int $budget, int $largestMaxRuntime, int $grace): int
    {
        return $budget + $largestMaxRuntime + $grace;
    }

    /** Rule lock expiry: max runtime plus grace. */
    public static function ruleTtl(int $maxRuntime, int $grace): int
    {
        return $maxRuntime + $grace;
    }

    public function take(string $name, string $owner, int $ttl): bool
    {
        if ($this->storage->insertLock($name, $owner, $ttl)) {
            return true;
        }
        return $this->storage->takeExpiredLock($name, $owner, $ttl);
    }

    public function renew(string $name, string $owner, int $ttl): bool
    {
        return $this->storage->renewLock($name, $owner, $ttl);
    }

    public function release(string $name, string $owner): bool
    {
        return $this->storage->releaseLock($name, $owner);
    }

    public function owns(string $name, string $owner): bool
    {
        $lock = $this->storage->getLock($name);
        return $lock !== null && hash_equals($lock['owner'], $owner);
    }

    /**
     * Remove a lock by name. Without $force only an expired lock goes.
     *
     * @throws LockException When the lock is held and unexpired and $force is false.
     * @return bool False when there was no such lock.
     */
    public function unlock(string $name, bool $force): bool
    {
        $lock = $this->storage->getLock($name);
        if ($lock === null) {
            return false;
        }
        if ($force) {
            return $this->storage->deleteLock($name);
        }
        if ($this->storage->deleteExpiredLock($name)) {
            return true;
        }
        throw new LockException(sprintf(
            'Lock %s is held by %s until %s (UTC). Use --force to remove it anyway.',
            $name,
            substr($lock['owner'], 0, 8),
            gmdate('Y-m-d H:i:s', $lock['expires'])
        ));
    }
}
