<?php
declare(strict_types=1);

return [
    'driver' => env('CACHE_DRIVER', 'file'),
    'path'   => WS_TEST_TMP . '/cache-data',
    'table'  => 'cache',
    'prefix' => '',
];
