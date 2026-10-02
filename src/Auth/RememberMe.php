<?php
declare(strict_types=1);

namespace Whitesmoke\Auth;

use InvalidArgumentException;
use Whitesmoke\Http\Request;

/**
 * "Remember me": a long-lived cookie that logs the user back in after the session ended.
 *
 * The cookie holds a random selector (to find the row) and a random validator; only a
 * SHA-256 hash of the validator is stored, so a leaked database cannot log anyone in.
 * A known selector with a wrong validator means a forged or stolen cookie: all of that
 * user's remembered logins are deleted. Tokens are tied to the password like SessionUser,
 * so a password change revokes them. Expiry is fixed at issue time.
 *
 * Table: id, user_id, selector (24, unique), validator_hash (64), password_fingerprint (64),
 * expires_at (Unix time).
 */
final class RememberMe
{
    private const VALUE = '~^([0-9a-f]{24}):([0-9a-f]{64})\z~';

    private string $name;
    private bool $secure;
    private string $sameSite;

    /** @param array|null $cookie ['secure' => bool, 'samesite' => 'Lax'|'Strict']; default: config/session.php */
    public function __construct(
        private readonly int $days = 30,
        private readonly string $table = 'remember_tokens',
        private readonly string $users = 'users',
        ?array $cookie = null,
    ) {
        if ($days < 1 || $days > 365) {
            throw new InvalidArgumentException('Remember-me must last between 1 and 365 days');
        }
        foreach ([$table, $users] as $name) {
            if (!preg_match('~^[a-z_][a-z0-9_]*\z~', $name)) {
                throw new InvalidArgumentException("Invalid table name: {$name}");
            }
        }

        $cookie ??= require BASE_PATH . '/config/session.php';
        $this->secure   = ($cookie['secure'] ?? true) !== false;
        $this->sameSite = (string) ($cookie['samesite'] ?? 'Lax');

        if (!in_array($this->sameSite, ['Lax', 'Strict'], true)) {
            throw new InvalidArgumentException('Remember-me cookie SameSite must be Lax or Strict');
        }

        // __Host-: browsers accept it only over HTTPS, from this host, for the whole site.
        $this->name = $this->secure ? '__Host-ws_remember' : 'ws_remember';
    }

    /** The cookie's name: __Host-ws_remember over HTTPS, ws_remember otherwise. */
    public function cookieName(): string
    {
        return $this->name;
    }

    /** Remember the user on this device. Call right after a successful login. */
    public function issue(array $user): void
    {
        if (!isset($user['id'], $user['password']) || !is_string($user['password'])) {
            throw new InvalidArgumentException('issue() needs the user row with id and password');
        }

        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires   = time() + $this->days * 86400;

        table($this->table)->insert([
            'user_id'              => (int) $user['id'],
            'selector'             => $selector,
            'validator_hash'       => hash('sha256', $validator),
            'password_fingerprint' => hash('sha256', $user['password']),
            'expires_at'           => $expires,
        ]);

        if (random_int(1, 50) === 1) {
            table($this->table)->where('expires_at', '<', time())->delete();
        }

        $this->cookie("{$selector}:{$validator}", $expires);
    }

    /**
     * The users row remembered by this request's cookie, or null. An invalid, unknown,
     * expired or revoked cookie is removed from the browser.
     */
    public function user(Request $request): ?array
    {
        $value = $request->cookie($this->name);

        if ($value === null) {
            return null;
        }

        $row = preg_match(self::VALUE, $value, $m) ? table($this->table)->where('selector', '=', $m[1])->first() : null;

        if ($row === null) {
            $this->cookie('', 1);
            return null;
        }

        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $m[2]))) {
            $this->clear((int) $row['user_id']);
            $this->cookie('', 1);
            logger()->warning('Remember-me cookie with a wrong secret; all remembered logins of the user were removed', ['user_id' => (int) $row['user_id']]);
            return null;
        }

        $user = (int) $row['expires_at'] > time()
            ? table($this->users)->where('id', '=', (int) $row['user_id'])->first()
            : null;

        if ($user === null || !hash_equals((string) $row['password_fingerprint'], hash('sha256', (string) $user['password']))) {
            table($this->table)->where('selector', '=', $m[1])->delete();
            $this->cookie('', 1);
            return null;
        }

        return $user;
    }

    /** Forget this device (at logout): delete its token and the cookie. */
    public function forget(Request $request): void
    {
        $value = $request->cookie($this->name);

        if ($value !== null && preg_match(self::VALUE, $value, $m)) {
            table($this->table)->where('selector', '=', $m[1])->delete();
        }

        $this->cookie('', 1);
    }

    /** Delete every remembered login of the user, on all devices. */
    public function clear(int $userId): void
    {
        table($this->table)->where('user_id', '=', $userId)->delete();
    }

    private function cookie(string $value, int $expires): void
    {
        setcookie($this->name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => $this->sameSite,
        ]);
    }
}
