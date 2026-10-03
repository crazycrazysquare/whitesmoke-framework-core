<?php
declare(strict_types=1);

namespace Whitesmoke\Session;

use InvalidArgumentException;

final class Session
{
    private bool $started = false;
    private array $flashNow = [];
    private readonly bool $secure;
    private readonly string $cookie;

    public function __construct(private readonly array $config)
    {
        $name = $config['name'] ?? null;

        // The prefix is added below; a name of digits only is refused by PHP.
        if (!is_string($name) || !preg_match('~^[A-Za-z][A-Za-z0-9_-]{0,63}\z~', $name)) {
            throw new InvalidArgumentException('Session cookie name must start with a letter and use only letters, digits, _ and - (at most 64)');
        }

        // __Host-: browsers accept the cookie only over HTTPS, from this exact host, for
        // the whole site. A sibling subdomain or a plain-HTTP page cannot set or replace it.
        $this->secure = ($config['secure'] ?? true) !== false;
        $this->cookie = $this->secure ? '__Host-' . $name : $name;
    }

    /** The cookie's name: __Host-<name> when the cookie is secure, <name> otherwise. */
    public function cookieName(): string
    {
        return $this->cookie;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->ensure(false)) {
            return $default;
        }
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->ensure(true);
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->ensure(true);
        unset($_SESSION[$key]);
    }

    public function token(): string
    {
        $this->ensure(true);
        return $_SESSION['_token'] ??= bin2hex(random_bytes(32));
    }

    public function flash(string $key, mixed $value): void
    {
        $this->ensure(true);
        $_SESSION['_flash'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        if (!$this->ensure(false)) {
            return $default;
        }
        return $this->flashNow[$key] ?? $default;
    }

    public function regenerate(): void
    {
        $this->ensure(true);
        session_regenerate_id(true);
        $_SESSION['_token'] = bin2hex(random_bytes(32));
    }

    public function invalidate(): void
    {
        $this->ensure(true);
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_created'] = $_SESSION['_last'] = time();
    }

    public function close(): void
    {
        if ($this->started) {
            session_write_close();
            $this->started = false;
        }
    }

    private function ensure(bool $create): bool
    {
        if ($this->started) {
            return true;
        }

        if (!$create && !isset($_COOKIE[$this->cookie])) {
            return false;
        }

        $this->start();
        return true;
    }

    private function start(): void
    {
        if (!is_dir($this->config['path'])) {
            mkdir($this->config['path'], 0700, true);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', 'nocache');
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_maxlifetime', (string) $this->config['absolute']);

        session_name($this->cookie);
        session_save_path($this->config['path']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',   // never a session.cookie_domain from php.ini: __Host- forbids it
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => $this->config['samesite'],
        ]);

        session_start();

        $now     = time();
        $created = $_SESSION['_created'] ?? $now;
        $last    = $_SESSION['_last'] ?? $now;

        if ($now - $last > $this->config['idle'] || $now - $created > $this->config['absolute']) {
            $_SESSION = [];
            session_regenerate_id(true);
            $created = $now;
        }

        $_SESSION['_created'] = $created;
        $_SESSION['_last']    = $now;

        $this->flashNow = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        $this->started = true;
    }
}
