<?php
declare(strict_types=1);

namespace Whitesmoke\Session;

final class Session
{
    private bool $started = false;
    private array $flashNow = [];

    public function __construct(private readonly array $config) {}

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

        if (!$create && !isset($_COOKIE[$this->config['name']])) {
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

        session_name($this->config['name']);
        session_save_path($this->config['path']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $this->config['secure'],
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
