<?php
declare(strict_types=1);

/*
 * Tests run inside a small fixture app (tests/Fixtures/app) so helpers like
 * db(), view(), session() and logger() find their config.
 *
 * Database: SQLite in memory by default. Set DB_CONNECTION=pgsql or mysql
 * (plus DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD) to test a real server.
 * Use an empty, throwaway database: tests create and drop tables.
 */

require __DIR__ . '/../vendor/autoload.php';

define('BASE_PATH', __DIR__ . '/Fixtures/app');
define('WS_TEST_TMP', sys_get_temp_dir() . '/whitesmoke-tests-' . getmypid());

if (!is_dir(WS_TEST_TMP)) {
    mkdir(WS_TEST_TMP, 0700, true);
}

register_shutdown_function(static function (): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(WS_TEST_TMP, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir(WS_TEST_TMP);
});
