<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Storage;

use IndianOpinion\ImportScheduler\Exception\SchemaException;
use IndianOpinion\ImportScheduler\Exception\StorageException;

/**
 * Connection over the global $wpdb. Reads always go to the database, never
 * to the object cache: a cached lock is a wrong lock.
 */
final class WpdbConnection implements Connection
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    public function prefix(): string
    {
        return $this->wpdb->prefix;
    }

    public function execute(string $sql, array $params = []): int
    {
        $result = $this->wpdb->query($this->prepare($sql, $params));
        if ($result === false) {
            $this->fail();
        }
        return (int) $result;
    }

    public function select(string $sql, array $params = []): array
    {
        $rows = $this->wpdb->get_results($this->prepare($sql, $params), ARRAY_A);
        if ($this->wpdb->last_error !== '') {
            $this->fail();
        }
        if (!is_array($rows)) {
            return [];
        }
        /** @var list<array<string, mixed>> $rows */
        return array_values($rows);
    }

    public function lastInsertId(): int
    {
        return (int) $this->wpdb->insert_id;
    }

    /**
     * @param list<int|string|null> $params
     */
    private function prepare(string $sql, array $params): string
    {
        if ($params === []) {
            return $sql;
        }
        $prepared = $this->wpdb->prepare($sql, ...$params);
        if (!is_string($prepared) || $prepared === '') {
            throw new StorageException('Could not prepare a statement.');
        }
        return $prepared;
    }

    /**
     * @return never
     */
    private function fail(): void
    {
        $error = $this->wpdb->last_error !== '' ? $this->wpdb->last_error : 'unknown database error';
        if (stripos($error, "doesn't exist") !== false || stripos($error, 'no such table') !== false) {
            throw new SchemaException('Import scheduler tables are missing: ' . $error);
        }
        throw new StorageException('Database error: ' . $error);
    }
}
