<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Integration;

use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\Storage\Schema;
use IndianOpinion\ImportScheduler\Storage\SqlStorage;
use IndianOpinion\ImportScheduler\Tests\Support\PdoConnection;
use PHPUnit\Framework\TestCase;

/**
 * T20 against a real MySQL or MariaDB server, with real concurrency: several
 * separate PHP processes, each on its own connection, race to take one expired
 * lock. Exactly one may win.
 *
 * Skipped unless IMPORT_SCHEDULER_TEST_DSN is set, for example:
 *   IMPORT_SCHEDULER_TEST_DSN='mysql:host=127.0.0.1;dbname=test' \
 *   IMPORT_SCHEDULER_TEST_USER=root IMPORT_SCHEDULER_TEST_PASSWORD=secret vendor/bin/phpunit
 */
final class MysqlLockRaceTest extends TestCase
{
    private const PREFIX = 'istest_';
    private const RACERS = 8;

    public function testExactlyOneOfManyProcessesTakesAnExpiredLock(): void
    {
        $dsn = getenv('IMPORT_SCHEDULER_TEST_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('Set IMPORT_SCHEDULER_TEST_DSN to run the MySQL lock race test.');
        }
        $setup = $this->connect($dsn);
        $schema = new Schema($setup);
        $schema->drop();
        $this->assertSame([], $schema->install());
        $storage = new SqlStorage($setup);
        $this->assertTrue($storage->insertLock('rule:race', str_repeat('0', 32), 60));
        $setup->execute('UPDATE ' . self::PREFIX . 'import_scheduler_locks SET expires = UNIX_TIMESTAMP() - 5');

        $start = sprintf('%.6F', microtime(true) + 1.5);
        $racers = [];
        for ($i = 0; $i < self::RACERS; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __DIR__ . '/race-worker.php', $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $racers[] = [$process, $pipes];
        }
        $wins = 0;
        $answers = [];
        foreach ($racers as [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $answers[] = trim($out . $err);
            $wins += trim($out) === 'won' ? 1 : 0;
        }
        $this->assertSame(self::RACERS, count(array_filter($answers, static fn (string $a): bool => $a === 'won' || $a === 'lost')), implode(' | ', $answers));
        $this->assertSame(1, $wins);
        $lock = $storage->getLock('rule:race');
        $this->assertNotNull($lock);
        $this->assertNotSame(str_repeat('0', 32), $lock['owner']);
        $this->assertGreaterThan($storage->now(), $lock['expires']);
        $schema->drop();
    }

    private function connect(string $dsn): PdoConnection
    {
        $user = getenv('IMPORT_SCHEDULER_TEST_USER');
        $password = getenv('IMPORT_SCHEDULER_TEST_PASSWORD');
        $pdo = new \PDO($dsn, is_string($user) ? $user : null, is_string($password) ? $password : null);
        return new PdoConnection($pdo, self::PREFIX);
    }
}
