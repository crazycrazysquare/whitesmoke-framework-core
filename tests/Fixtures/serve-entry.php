<?php
declare(strict_types=1);

/*
 * "php smoke serve" for ServeCommandTest, for an app in any folder:
 *   php serve-entry.php <app folder> <port>
 */

require __DIR__ . '/../../vendor/autoload.php';

exit((new Whitesmoke\Console\Console($argv[1]))->run(['smoke', 'serve', '--port=' . $argv[2]]));
