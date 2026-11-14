<?php

/**
 * One racer for MysqlLockRaceTest: waits for the start time, tries to take
 * the lock once on its own connection, prints "won" or "lost".
 *
 * Usage: php race-worker.php <start unix time with fraction>
 */

declare(strict_types=1);

use IndianOpinion\ImportScheduler\Locks;
use IndianOpinion\ImportScheduler\Storage\SqlStorage;
use IndianOpinion\ImportScheduler\Tests\Support\PdoConnection;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$start = (float) ($argv[1] ?? '0');
$user = getenv('IMPORT_SCHEDULER_TEST_USER');
$password = getenv('IMPORT_SCHEDULER_TEST_PASSWORD');
$pdo = new PDO((string) getenv('IMPORT_SCHEDULER_TEST_DSN'), is_string($user) ? $user : null, is_string($password) ? $password : null);
$locks = new Locks(new SqlStorage(new PdoConnection($pdo, 'istest_')));
$owner = Locks::newOwner();
while (microtime(true) < $start) {
    usleep(100);
}
echo $locks->take('rule:race', $owner, 60) ? 'won' : 'lost';
