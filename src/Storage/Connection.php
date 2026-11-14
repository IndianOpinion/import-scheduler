<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Storage;

use IndianOpinion\ImportScheduler\Exception\StorageException;

/**
 * A thin database connection. Placeholders are %s and %d. Table names come
 * from the prefix only, never from input. Null values are written by the
 * caller as a literal NULL, never as a placeholder.
 */
interface Connection
{
    public function prefix(): string;

    /**
     * Run a write statement.
     *
     * @param list<int|string|null> $params
     * @return int Affected rows.
     * @throws StorageException
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * @param list<int|string|null> $params
     * @return list<array<string, mixed>>
     * @throws StorageException
     */
    public function select(string $sql, array $params = []): array;

    public function lastInsertId(): int;
}
