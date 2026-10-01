<?php
declare(strict_types=1);

return [
    'name'     => 'ws_test',
    'path'     => WS_TEST_TMP . '/sessions',
    'idle'     => 1800,
    'absolute' => 28800,
    'secure'   => false,
    'samesite' => 'Lax',
];
