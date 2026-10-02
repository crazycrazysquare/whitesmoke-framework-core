<?php
declare(strict_types=1);

/*
 * One of several simultaneous cache users for CacheRaceTest:
 *   php cache-worker.php <file|database> <file store dir> <start time> <worker number>
 * Prints JSON: how many writes threw, and reads that were neither a miss nor a whole value.
 */

use Whitesmoke\Cache\Cache;
use Whitesmoke\Cache\DatabaseStore;
use Whitesmoke\Cache\FileStore;

require __DIR__ . '/../../vendor/autoload.php';

define('BASE_PATH', __DIR__ . '/app');

[, $driver, $dir, $start, $worker] = $argv;

$cache = new Cache($driver === 'file' ? new FileStore($dir) : new DatabaseStore('race_cache'));
db();

while (microtime(true) < (float) $start) {
    usleep(100);
}

$errors = $bad = 0;
for ($i = 0; $i < 60; $i++) {
    try {
        $cache->set('shared', ['worker' => (int) $worker, 'pad' => str_repeat('x', 1000)]);
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, $e->getMessage() . "\n");
    }
    $value = $cache->get('shared');
    if ($value !== null && strlen($value['pad'] ?? '') !== 1000) {
        $bad++;
    }
}

echo json_encode(['errors' => $errors, 'bad' => $bad]);
