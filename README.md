# whitesmoke/core

The engine of the Whitesmoke Framework. Install it through the
[whitesmoke/framework](https://github.com/crazycrazysquare/whitesmoke-framework-starter)
skeleton, not directly.

Contains: Application (front controller), .env loading (vlucas/phpdotenv),
Request/Response, Router,
View, Session, CSRF middleware, login Throttle, Validator,
Database Connection (mysql, pgsql, sqlite, sqlsrv) and Query builder.

Requires PHP 8.3+ with pdo, mbstring and fileinfo.

## Running the tests

    composer install
    composer test                     # SQLite in memory

Against a real database (use an empty, throwaway database; tests create and drop tables):

    DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=whitesmoke_test DB_USERNAME=me DB_PASSWORD=secret composer test
    DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=whitesmoke_test DB_USERNAME=me DB_PASSWORD=secret composer test

`composer test` runs `phpunit --stderr`; session tests need the `--stderr` flag.

## License

MIT. See `LICENSE`.
