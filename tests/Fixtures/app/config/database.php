<?php
declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],

        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => (string) env('DB_HOST', '127.0.0.1'),
            'port'     => (int) env('DB_PORT', 5432),
            'database' => (string) env('DB_DATABASE', 'whitesmoke_test'),
            'username' => (string) env('DB_USERNAME', ''),
            'password' => (string) env('DB_PASSWORD', ''),
            'schema'   => 'public',
            'sslmode'  => (string) env('DB_SSLMODE', 'prefer'),
        ],

        'mysql' => [
            'driver'   => 'mysql',
            'host'     => (string) env('DB_HOST', '127.0.0.1'),
            'port'     => (int) env('DB_PORT', 3306),
            'database' => (string) env('DB_DATABASE', 'whitesmoke_test'),
            'username' => (string) env('DB_USERNAME', ''),
            'password' => (string) env('DB_PASSWORD', ''),
            'charset'  => 'utf8mb4',
        ],

        'sqlsrv' => [
            'driver'   => 'sqlsrv',
            'host'     => (string) env('DB_HOST', '127.0.0.1'),
            'port'     => (int) env('DB_PORT', 1433),
            'database' => (string) env('DB_DATABASE', 'whitesmoke_test'),
            'username' => (string) env('DB_USERNAME', ''),
            'password' => (string) env('DB_PASSWORD', ''),
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', false),
        ],
    ],
];
