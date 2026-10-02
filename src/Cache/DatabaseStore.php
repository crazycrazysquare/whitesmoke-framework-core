<?php
declare(strict_types=1);

namespace Whitesmoke\Cache;

use InvalidArgumentException;
use PDOException;

/**
 * Entries in a database table, shared by every server that uses the database.
 * Works on MySQL, PostgreSQL, SQLite and SQL Server.
 *
 * Table: id, cache_key (200, unique), payload (text), expires_at (Unix time, 0 = never).
 */
final class DatabaseStore implements Store
{
    public function __construct(private readonly string $table = 'cache', private readonly ?string $connection = null)
    {
        if (!preg_match('~^[a-z_][a-z0-9_]*\z~', $table)) {
            throw new InvalidArgumentException("Invalid cache table name: {$table}");
        }
    }

    public function get(string $key): ?string
    {
        $row = table($this->table, $this->connection)->select('payload', 'expires_at')->where('cache_key', '=', $key)->first();

        if ($row === null) {
            return null;
        }

        if ((int) $row['expires_at'] !== 0 && (int) $row['expires_at'] <= time()) {
            $this->delete($key);
            return null;
        }

        return (string) $row['payload'];
    }

    public function put(string $key, string $payload, int $expires): void
    {
        $values = ['payload' => $payload, 'expires_at' => $expires];

        if ($this->exists($key)) {
            table($this->table, $this->connection)->where('cache_key', '=', $key)->update($values);
            return;
        }

        try {
            table($this->table, $this->connection)->insert(['cache_key' => $key] + $values);
        } catch (PDOException $e) {
            // Another request inserted the same key at the same moment: overwrite it.
            if (!$this->exists($key)) {
                throw $e;
            }
            table($this->table, $this->connection)->where('cache_key', '=', $key)->update($values);
        }
    }

    public function delete(string $key): bool
    {
        return table($this->table, $this->connection)->where('cache_key', '=', $key)->delete() > 0;
    }

    public function clear(): int
    {
        return table($this->table, $this->connection)->where('id', '>', 0)->delete();
    }

    private function exists(string $key): bool
    {
        return table($this->table, $this->connection)->where('cache_key', '=', $key)->count() > 0;
    }
}
