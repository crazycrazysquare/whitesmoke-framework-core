<?php
declare(strict_types=1);

namespace Whitesmoke\Database;

use InvalidArgumentException;
use PDO;

final class Connection
{
    public static function make(array $c): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        return match ($c['driver'] ?? null) {
            'mysql'  => self::mysql($c, $options),
            'pgsql'  => self::pgsql($c, $options),
            'sqlite' => self::sqlite($c, $options),
            'sqlsrv' => self::sqlsrv($c, $options),
            default  => throw new InvalidArgumentException('Unsupported DB driver'),
        };
    }

    private static function mysql(array $c, array $options): PDO
    {
        self::guard($c['host'], $c['database'], $c['charset']);

        $multi = \defined('Pdo\Mysql::ATTR_MULTI_STATEMENTS')
            ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS
            : PDO::MYSQL_ATTR_MULTI_STATEMENTS;
        $options[$multi] = false;

        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset={$c['charset']}";

        return new PDO($dsn, $c['username'], $c['password'], $options);
    }

    private static function pgsql(array $c, array $options): PDO
    {
        self::guard($c['host'], $c['database'], $c['sslmode']);

        if (!preg_match('~^[a-z_][a-z0-9_]*$~i', $c['schema'])) {
            throw new InvalidArgumentException('Invalid schema name');
        }

        $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']};sslmode={$c['sslmode']}";
        $pdo = new PDO($dsn, $c['username'], $c['password'], $options);

        $pdo->exec("SET client_encoding TO 'UTF8'");
        $pdo->exec('SET search_path TO "' . $c['schema'] . '"');

        return $pdo;
    }

    private static function sqlite(array $c, array $options): PDO
    {
        $pdo = new PDO('sqlite:' . $c['database'], null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private static function sqlsrv(array $c, array $options): PDO
    {
        self::guard($c['host'], $c['database']);

        // Encryption is always on. Trusting the certificate without checking it is
        // opt-in, for servers with a self-signed certificate (local, Docker).
        $trust = $c['trust_server_certificate'] ?? false;

        if (!is_bool($trust)) {
            throw new InvalidArgumentException('trust_server_certificate must be true or false');
        }

        unset($options[PDO::ATTR_EMULATE_PREPARES]);

        $dsn = "sqlsrv:Server={$c['host']},{$c['port']};Database={$c['database']};Encrypt=yes;TrustServerCertificate=" . ($trust ? 'yes' : 'no');

        return new PDO($dsn, $c['username'], $c['password'], $options);
    }

    private static function guard(string ...$values): void
    {
        foreach ($values as $value) {
            if ($value === '' || strpbrk($value, ";=\0") !== false) {
                throw new InvalidArgumentException('Invalid DB connection value');
            }
        }
    }
}
