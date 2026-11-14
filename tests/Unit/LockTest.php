<?php

declare(strict_types=1);

namespace IndianOpinion\ImportScheduler\Tests\Unit;

use IndianOpinion\ImportScheduler\Exception\LockException;
use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\Storage\SqlStorage;
use IndianOpinion\ImportScheduler\Tests\Support\MemoryStorage;
use IndianOpinion\ImportScheduler\Tests\Support\RecordingConnection;
use IndianOpinion\ImportScheduler\Tests\TestCase;

final class LockTest extends TestCase
{
    /** T20 (4.5.2, 4.5.1) */
    public function testOfTwoProcessesTakingAnExpiredLockExactlyOneWins(): void
    {
        $a = new Locks(new MemoryStorage($this->db));
        $b = new Locks(new MemoryStorage($this->db));
        $ownerA = Locks::newOwner();
        $ownerB = Locks::newOwner();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $ownerA);
        $this->assertNotSame($ownerA, $ownerB);

        // Free lock, both insert: the primary key lets one in.
        $this->assertTrue($a->take('rule:x', $ownerA, 60));
        $this->assertFalse($b->take('rule:x', $ownerB, 60));
        $this->assertSame($ownerA, $this->db->locks['rule:x']['owner']);

        // Expired lock, both take over: the conditional update lets one in.
        $this->clock->advance(61);
        $wins = 0;
        foreach ([[$a, $ownerA], [$b, $ownerB], [$a, $ownerA]] as [$locks, $owner]) {
            $wins += $locks->take('rule:x', $owner, 60) ? 1 : 0;
        }
        $this->assertSame(1, $wins);
        $lock = $this->db->locks['rule:x'];
        $this->assertSame(['name', 'owner', 'acquired', 'expires', 'heartbeat'], array_keys($lock));
        $this->assertSame($this->db->now() + 60, $lock['expires']);

        // The SQL storage takes over in one conditional statement.
        $sql = new RecordingConnection();
        (new SqlStorage($sql))->takeExpiredLock('rule:x', $ownerA, 60);
        $this->assertCount(1, $sql->statements);
        $this->assertStringContainsString('WHERE name = %s AND expires < UNIX_TIMESTAMP()', $sql->sql()[0]);
        $this->assertStringStartsWith('UPDATE', $sql->sql()[0]);
    }

    /** T21 (4.5.3, 4.5.4, 4.4.9) */
    public function testReleaseAndRenewByANonOwnerChangeNothing(): void
    {
        $locks = new Locks($this->storage);
        $owner = Locks::newOwner();
        $locks->take('lane:default:1', $owner, 100);
        $before = $this->db->locks;
        $this->clock->advance(5);
        $this->assertFalse($locks->release('lane:default:1', Locks::newOwner()));
        $this->assertFalse($locks->renew('lane:default:1', Locks::newOwner(), 1000));
        $this->assertSame($before, $this->db->locks);
        $this->assertTrue($locks->renew('lane:default:1', $owner, 1000));
        $this->assertSame($this->db->now() + 1000, $this->db->locks['lane:default:1']['expires']);
        $this->assertTrue($locks->release('lane:default:1', $owner));
        $this->assertFalse($locks->release('lane:default:1', $owner), 'already gone');
    }

    /** T22 (4.5.5, 4.5.6, 4.5.7) */
    public function testExpiryValuesAndTakeoverOfAnExpiredLock(): void
    {
        $this->assertSame(55 + 900 + 60, Locks::laneTtl(55, 900, 60));
        $this->assertSame(120 + 60, Locks::ruleTtl(120, 60));
        $seen = [];
        $this->registry->register('r', function ($context) use (&$seen): void {
            $seen['lane'] = $this->db->locks['lane:default:1']['expires'];
            $seen['rule'] = $this->db->locks['rule:r']['expires'];
            $this->clock->advance(30);
            $seen['beat'] = $context->heartbeat();
            $seen['after'] = $this->db->locks['rule:r']['expires'];
        }, ['interval' => 600, 'max_runtime' => 120]);
        $this->rule('other', 0, null, ['interval' => 600, 'max_runtime' => 900, 'lane' => 'elsewhere']);
        $this->registry->register('big', static fn () => null, ['interval' => 3600, 'max_runtime' => 400]);
        $now = $this->db->now();
        $this->tick();
        $this->assertSame($now + 55 + 400 + 60, $seen['lane'], 'budget + largest max runtime in the lane + grace');
        $this->assertSame($now + 120 + 60, $seen['rule']);
        $this->assertTrue($seen['beat']);
        $this->assertSame($now + 30 + 120 + 60, $seen['after']);

        // A crashed holder: its lock expires and is simply taken.
        $this->db->locks['lane:default:1'] = ['name' => 'lane:default:1', 'owner' => str_repeat('d', 32), 'acquired' => $now - 2000, 'expires' => $this->db->now() - 1, 'heartbeat' => $now - 2000];
        $this->plugin->operator()->markDue('r');
        [$code, $lines] = $this->tick();
        $this->assertSame(0, $code);
        $this->assertNotContains('lane busy', $lines);
        $this->assertCount(2, $this->runs('r'));
    }

    /** T23 (4.5.9) */
    public function testLockComparisonsUseTheDatabaseClock(): void
    {
        // The database clock is an hour behind this PHP process.
        $this->db->setNow(time() - 3600);
        $locks = new Locks($this->storage);
        $locks->take('rule:x', Locks::newOwner(), 600);
        // By PHP's clock the lock expired 50 minutes ago; by the database's it is held.
        $this->assertFalse($locks->take('rule:x', Locks::newOwner(), 600));
        $this->clock->advance(601);
        $this->assertTrue($locks->take('rule:x', Locks::newOwner(), 600));

        $sql = new RecordingConnection();
        $storage = new SqlStorage($sql);
        $storage->insertLock('n', 'o', 60);
        $storage->takeExpiredLock('n', 'o', 60);
        $storage->renewLock('n', 'o', 60);
        $storage->deleteExpiredLock('n');
        $writes = array_filter($sql->statements, static fn (array $s): bool => !str_starts_with($s[0], 'SELECT'));
        $this->assertCount(4, $writes);
        foreach ($writes as [$statement, $params]) {
            $this->assertStringContainsString('UNIX_TIMESTAMP()', $statement);
            foreach ($params as $param) {
                $this->assertFalse(is_int($param) && $param > 1_000_000_000, 'no PHP timestamp is passed in');
            }
        }
        $this->assertSame([], $this->sourceMatches('/(?<![\w>:$])time\(\)/', ['Fallback.php']), 'the engine never reads PHP\'s clock');
    }

    /** T24 (4.5.10) */
    public function testUnlockRefusesAnUnexpiredLockWithoutForce(): void
    {
        $owner = Locks::newOwner();
        $this->storage->insertLock('lane:default:1', $owner, 600);
        [$cli, $io] = $this->cli();
        $cli->unlock(['lane:default:1'], []);
        $this->assertSame(1, $io->code());
        $this->assertStringContainsString('held by ' . substr($owner, 0, 8), $io->text());
        $this->assertArrayHasKey('lane:default:1', $this->db->locks);

        [$cli, $io] = $this->cli();
        $cli->unlock(['lane:default:1'], ['force' => true]);
        $this->assertSame(0, $io->code());
        $this->assertStringContainsString('Removing lane:default:1 held by ' . substr($owner, 0, 8), $io->text());
        $this->assertArrayNotHasKey('lane:default:1', $this->db->locks);

        $this->storage->insertLock('rule:old', $owner, 10);
        $this->clock->advance(11);
        [$cli, $io] = $this->cli();
        $cli->unlock(['rule:old'], []);
        $this->assertArrayNotHasKey('rule:old', $this->db->locks, 'an expired lock goes without force');

        $this->expectException(LockException::class);
        $this->storage->insertLock('rule:new', $owner, 100);
        (new Locks($this->storage))->unlock('rule:new', false);
    }

    /** T65 (4.5.8) */
    public function testNoLockFilesFlockOrNamedLocks(): void
    {
        $this->assertSame([], $this->sourceMatches('/\bflock\s*\(|GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK|\.lock[\'"]|sys_get_temp_dir|fopen\s*\(|file_put_contents\s*\(|tempnam\s*\(/i'));
    }

    /**
     * @param list<string> $skip
     * @return list<string>
     */
    private function sourceMatches(string $pattern, array $skip = []): array
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php' || in_array($file->getFilename(), $skip, true)) {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[] = $file->getFilename() . ':' . ($n + 1);
                }
            }
        }
        return $hits;
    }
}
