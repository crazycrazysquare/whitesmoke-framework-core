<?php
declare(strict_types=1);

namespace Whitesmoke\Auth;

use InvalidArgumentException;

/**
 * One-time password reset tokens. The token goes into the emailed link; only its
 * SHA-256 hash is stored, so someone who reads the database cannot reset a
 * password. A token expires after $lifetime seconds and works once.
 *
 * Table: id, user_id, token_hash (64 chars, unique), expires_at (Unix time).
 */
final class PasswordResets
{
    public function __construct(private readonly int $lifetime = 3600, private readonly string $table = 'password_resets')
    {
        if ($lifetime < 60 || $lifetime > 86400) {
            throw new InvalidArgumentException('Reset token lifetime must be between 60 seconds and 24 hours');
        }
        if (!preg_match('~^[a-z_][a-z0-9_]*$~', $table)) {
            throw new InvalidArgumentException("Invalid password reset table name: {$table}");
        }
    }

    /** A new token for the user; earlier tokens for that user stop working. */
    public function create(int $userId): string
    {
        $token = bin2hex(random_bytes(32));

        table($this->table)->where('user_id', '=', $userId)->delete();
        table($this->table)->insert([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => time() + $this->lifetime,
        ]);

        if (random_int(1, 50) === 1) {
            table($this->table)->where('expires_at', '<', time())->delete();
        }

        return $token;
    }

    /** The user id for a valid token, without using it up (to show the form). */
    public function check(string $token): ?int
    {
        if (!preg_match('~^[0-9a-f]{64}$~', $token)) {
            return null;
        }

        $row = table($this->table)
            ->where('token_hash', '=', hash('sha256', $token))
            ->where('expires_at', '>', time())
            ->first();

        return $row === null ? null : (int) $row['user_id'];
    }

    /**
     * Use the token: returns the user id once, then null. Of two simultaneous
     * uses, only the one that deletes the row succeeds.
     */
    public function consume(string $token): ?int
    {
        $userId = $this->check($token);

        if ($userId === null) {
            return null;
        }

        $deleted = table($this->table)
            ->where('token_hash', '=', hash('sha256', $token))
            ->where('expires_at', '>', time())
            ->delete();

        return $deleted === 1 ? $userId : null;
    }

    /** Remove every token of the user, e.g. after the password changed. */
    public function clear(int $userId): void
    {
        table($this->table)->where('user_id', '=', $userId)->delete();
    }
}
