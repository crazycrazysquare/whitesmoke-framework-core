<?php
declare(strict_types=1);

/*
 * One simultaneous login attempt for ThrottleRaceTest:
 * php throttle-worker.php <start time> <key> <max>
 * Connects first, waits for the shared start time, then prints hit()'s count.
 */

require __DIR__ . '/../../vendor/autoload.php';

define('BASE_PATH', __DIR__ . '/app');

db();

while (microtime(true) < (float) $argv[1]) {
    usleep(200);
}

echo (new Whitesmoke\Security\Throttle())->hit($argv[2], (int) $argv[3], 600);
