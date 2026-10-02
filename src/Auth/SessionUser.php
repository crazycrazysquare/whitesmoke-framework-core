<?php
declare(strict_types=1);

namespace Whitesmoke\Auth;

use InvalidArgumentException;
use Whitesmoke\Session\Session;

/**
 * The logged-in user, tied to the password they logged in with.
 *
 * At login, remember() stores the user id and a fingerprint (SHA-256) of the
 * user's password hash in the session. check() compares it with the database on
 * each request: when the password changed since login, for example through a
 * password reset, or the user was deleted, the session ends. One primary-key
 * query per check.
 */
final class SessionUser
{
    private const KEY = '_auth';

    public function __construct(private readonly ?Session $session = null, private readonly string $table = 'users')
    {
        if (!preg_match('~^[a-z_][a-z0-9_]*$~', $table)) {
            throw new InvalidArgumentException("Invalid users table name: {$table}");
        }
    }

    /**
     * Log the user in. Call after verifying the password and after any rehash, with the
     * row's current password hash. Regenerates the session id against fixation.
     */
    public function remember(array $user): void
    {
        if (!isset($user['id'], $user['password']) || !is_string($user['password'])) {
            throw new InvalidArgumentException('remember() needs the user row with id and password');
        }

        $session = $this->session();
        $session->regenerate();
        $session->put('user_id', (int) $user['id']);
        $session->put(self::KEY, self::fingerprint($user['password']));
    }

    /**
     * The logged-in user's row, or null for guests. A session from before a password
     * change, or of a deleted user, is ended (emptied, new id) and also gives null.
     */
    public function check(): ?array
    {
        $session = $this->session();
        $id      = $session->get('user_id');

        if ($id === null) {
            return null;
        }

        $user        = is_int($id) ? table($this->table)->where('id', '=', $id)->first() : null;
        $fingerprint = $session->get(self::KEY);

        if ($user === null || !is_string($fingerprint) || !hash_equals(self::fingerprint((string) $user['password']), $fingerprint)) {
            $session->invalidate();
            return null;
        }

        return $user;
    }

    private function session(): Session
    {
        return $this->session ?? session();
    }

    private static function fingerprint(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }
}
