<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Support;

use IndianOpinion\ImportScheduler\Exception\SchemaException;
use IndianOpinion\ImportScheduler\Exception\StorageException;
use IndianOpinion\ImportScheduler\Storage\Connection;

/**
 * A real MySQL connection for the integration test. Converts the %s and %d
 * placeholders to bound parameters.
 */
final class PdoConnection implements Connection
{
    public function __construct(private \PDO $pdo, private string $prefix)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->statement($sql, $params)->rowCount();
    }

    public function select(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->statement($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<int|string|null> $params
     */
    private function statement(string $sql, array $params): \PDOStatement
    {
        $types = [];
        $converted = (string) preg_replace_callback('/%([sd])/', static function (array $m) use (&$types): string {
            $types[] = $m[1];
            return '?';
        }, $sql);
        try {
            $statement = $this->pdo->prepare($converted);
            foreach ($params as $i => $value) {
                $statement->bindValue($i + 1, $value, ($types[$i] ?? 's') === 'd' ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $statement->execute();
            return $statement;
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1146) {
                throw new SchemaException($e->getMessage());
            }
            throw new StorageException($e->getMessage());
        }
    }
}
