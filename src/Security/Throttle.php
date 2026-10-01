<?php
declare(strict_types=1);

namespace Whitesmoke\Security;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Counts attempts per key and locks the key once a limit is reached.
 *
 * Every change is a single atomic UPDATE, so simultaneous requests can never
 * overwrite each other's counts.
 */
final class Throttle
{
    public function __construct(private readonly string $table = 'throttle')
    {
        if (!preg_match('~^[a-z_][a-z0-9_]*$~', $table)) {
            throw new InvalidArgumentException("Invalid throttle table name: {$table}");
        }
    }

    public function tooMany(string $key): bool
    {
        return $this->availableIn($key) > 0;
    }

    /** Seconds until the key is unlocked; 0 when it is not locked. */
    public function availableIn(string $key): int
    {
        $row = $this->find($key);

        return $row === null ? 0 : max(0, (int) $row['locked_until'] - time());
    }

    /**
     * Record one attempt and return the attempt count in the current window,
     * including this one. Locks the key for $decay seconds once the count reaches $max.
     */
    public function hit(string $key, int $max, int $decay): int
    {
        $now = time();

        $this->ensureRow($key, $now + $decay);

        // A finished window with no active lock starts over.
        $this->exec(
            "UPDATE {$this->table} SET attempts = 0, window_ends = ? WHERE throttle_key = ? AND window_ends < ? AND locked_until < ?",
            [$now + $decay, $key, $now, $now]
        );

        $attempts = $this->increment($key);

        if ($attempts >= $max) {
            // Lock once; later attempts during the lock do not extend it.
            $this->exec(
                "UPDATE {$this->table} SET locked_until = ?, window_ends = ? WHERE throttle_key = ? AND locked_until < ?",
                [$now + $decay, $now + $decay, $key, $now]
            );
        }

        if (random_int(1, 100) === 1) {
            $this->exec("DELETE FROM {$this->table} WHERE window_ends < ? AND locked_until < ?", [$now, $now]);
        }

        return $attempts;
    }

    public function clear(string $key): void
    {
        $this->exec("DELETE FROM {$this->table} WHERE throttle_key = ?", [$key]);
    }

    /** Add one and return the new count in the same atomic statement. */
    private function increment(string $key): int
    {
        $t = $this->table;

        $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite' && version_compare((string) db()->query('SELECT sqlite_version()')->fetchColumn(), '3.35.0', '<')) {
            // Older SQLite has no RETURNING. SQLite allows one writer at a time,
            // so an immediate transaction makes update + read atomic.
            db()->exec('BEGIN IMMEDIATE');
            try {
                $this->exec("UPDATE {$t} SET attempts = attempts + 1 WHERE throttle_key = ?", [$key]);
                $count = (int) ($this->find($key)['attempts'] ?? 0);
                db()->exec('COMMIT');
                return $count;
            } catch (\Throwable $e) {
                db()->exec('ROLLBACK');
                throw $e;
            }
        }

        return (int) match ($driver) {
            'pgsql', 'sqlite' => $this->exec("UPDATE {$t} SET attempts = attempts + 1 WHERE throttle_key = ? RETURNING attempts", [$key])->fetchColumn(),
            'sqlsrv'          => $this->exec("UPDATE {$t} SET attempts = attempts + 1 OUTPUT INSERTED.attempts WHERE throttle_key = ?", [$key])->fetchColumn(),
            'mysql'           => $this->mysqlIncrement($key),
        };
    }

    /** MySQL has no UPDATE ... RETURNING; LAST_INSERT_ID(expr) is atomic and per connection. */
    private function mysqlIncrement(string $key): int
    {
        $this->exec("UPDATE {$this->table} SET attempts = LAST_INSERT_ID(attempts + 1) WHERE throttle_key = ?", [$key]);

        return (int) db()->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    private function ensureRow(string $key, int $windowEnds): void
    {
        if ($this->find($key) !== null) {
            return;
        }

        try {
            $this->exec(
                "INSERT INTO {$this->table} (throttle_key, attempts, window_ends, locked_until) VALUES (?, 0, ?, 0)",
                [$key, $windowEnds]
            );
        } catch (PDOException $e) {
            // Another request created the row at the same moment; that is fine.
            if ($this->find($key) === null) {
                throw $e;
            }
        }
    }

    private function find(string $key): ?array
    {
        $stmt = $this->exec("SELECT attempts, window_ends, locked_until FROM {$this->table} WHERE throttle_key = ?", [$key]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function exec(string $sql, array $params): \PDOStatement
    {
        $stmt = db()->prepare($sql);

        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();

        return $stmt;
    }
}
