<?php
declare(strict_types=1);

namespace Whitesmoke\Http;

final class Request
{
    /** @param list<array{0: string, 1: int}> $proxies trusted proxy networks: binary address and prefix length */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        private readonly array $server,
        private readonly array $proxies,
        private readonly array $cookies = [],
    ) {}

    /**
     * @param list<string> $trustedProxies IP addresses or CIDR ranges of reverse proxies whose
     *                                     X-Forwarded-For and X-Forwarded-Proto headers are believed
     */
    public static function capture(array $trustedProxies = []): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::clean($path),
            self::clean($_GET),
            self::clean($_POST),
            self::normalizeFiles($_FILES),
            $_SERVER,
            array_map(self::network(...), array_values($trustedProxies)),
            $_COOKIE,
        );
    }

    /** A cookie's value; null when missing, not a string, or not valid UTF-8 without null bytes. */
    public function cookie(string $key): ?string
    {
        $value = $this->cookies[$key] ?? null;

        return is_string($value) && mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0") ? $value : null;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function post(string $key, ?string $default = null): ?string
    {
        $value = $this->body[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function getInt(string $key): ?int
    {
        return self::toInt($this->query[$key] ?? null);
    }

    public function postInt(string $key): ?int
    {
        return self::toInt($this->body[$key] ?? null);
    }

    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (isset($this->body[$key]) && is_string($this->body[$key])) {
                $out[$key] = $this->body[$key];
            }
        }
        return $out;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) && isset($file['tmp_name']) ? $file : null;
    }

    public function files(string $key): array
    {
        $files = $this->files[$key] ?? [];
        return isset($files['tmp_name']) ? [$files] : $files;
    }

    /**
     * Client IP address. Behind a trusted proxy, X-Forwarded-For is read from the
     * right: each trusted proxy is skipped and the first other address is the
     * client. Entries left of that are client-supplied and never used.
     */
    public function ip(): string
    {
        $hop = (string) ($this->server['REMOTE_ADDR'] ?? '');

        if (!$this->trusted($hop)) {
            return $hop;
        }

        $header = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
        $chain  = $header === '' ? [] : array_reverse(explode(',', $header));

        foreach ($chain as $entry) {
            $ip = self::forwardedIp(trim($entry, " \t"));

            if ($ip === null) {
                return $hop; // malformed: trust nothing beyond the last trusted hop
            }
            if (!$this->trusted($ip)) {
                return $ip;
            }
            $hop = $ip;
        }

        return $hop;
    }

    /**
     * Whether the client reached the site over HTTPS. Behind a trusted proxy that
     * ends TLS, the proxy says so with X-Forwarded-Proto: https (a single value).
     */
    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));

        if (($https !== '' && $https !== 'off') || (string) ($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        return $this->trusted((string) ($this->server['REMOTE_ADDR'] ?? ''))
            && strtolower(trim((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''), " \t")) === 'https';
    }

    private function trusted(string $ip): bool
    {
        $bin = $this->proxies === [] ? false : @inet_pton($ip);

        if ($bin === false) {
            return false;
        }

        foreach ($this->proxies as [$network, $bits]) {
            if (strlen($network) === strlen($bin) && self::prefixMatches($bin, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function prefixMatches(string $a, string $b, int $bits): bool
    {
        $bytes = intdiv($bits, 8);

        if (strncmp($a, $b, $bytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** One X-Forwarded-For entry as a normalized IP, or null if it is not one. Ports are dropped. */
    private static function forwardedIp(string $entry): ?string
    {
        if (preg_match('~^\[([0-9a-fA-F:.]+)\](?::\d{1,5})?\z~', $entry, $m)) {
            $entry = $m[1];
        } elseif (preg_match('~^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}\z~', $entry, $m)) {
            $entry = $m[1];
        }

        $bin = filter_var($entry, FILTER_VALIDATE_IP) === false ? false : @inet_pton($entry);

        return $bin === false ? null : (string) inet_ntop($bin);
    }

    /**
     * Parse one trusted proxy: an IP address or CIDR range. Fails closed: anything
     * else, and ranges that would trust every address, are refused.
     *
     * @return array{0: string, 1: int}
     */
    private static function network(mixed $proxy): array
    {
        $proxy = is_string($proxy) ? trim($proxy) : '';
        [$ip, $bits] = str_contains($proxy, '/') ? explode('/', $proxy, 2) : [$proxy, null];

        $bin = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : @inet_pton($ip);
        $max = $bin === false ? 0 : strlen($bin) * 8;

        if ($bits === null) {
            $bits = (string) $max;
        }

        if ($bin === false || !ctype_digit($bits) || (int) $bits < 1 || (int) $bits > $max) {
            throw new \InvalidArgumentException(
                'Invalid trusted proxy "' . $proxy . '": use an IP address or CIDR range such as 10.0.0.0/8. Trusting every address is not allowed.'
            );
        }

        return [$bin, (int) $bits];
    }

    private static function toInt(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : $int;
    }

    private static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[self::clean((string) $k)] = self::clean($v);
            }
            return $out;
        }

        if (!is_string($value)) {
            return $value;
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new BadRequest('Invalid UTF-8 in request');
        }

        return str_replace("\0", '', $value);
    }

    private static function normalizeFiles(array $files): array
    {
        $out = [];

        foreach ($files as $field => $info) {
            if (!is_array($info['name'] ?? null)) {
                $out[$field] = self::fileEntry($info['name'] ?? '', $info['type'] ?? '', $info['tmp_name'] ?? '', $info['error'] ?? UPLOAD_ERR_NO_FILE, $info['size'] ?? 0);
                continue;
            }

            foreach (array_keys($info['name']) as $i) {
                $out[$field][$i] = self::fileEntry($info['name'][$i], $info['type'][$i], $info['tmp_name'][$i], $info['error'][$i], $info['size'][$i]);
            }
        }

        return $out;
    }

    private static function fileEntry(string $name, string $clientType, string $tmp, int $error, int $size): array
    {
        $type = null;

        if ($error === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
            $type = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: null;
        }

        return [
            'name'        => basename($name),
            'client_type' => $clientType,
            'type'        => $type,
            'tmp_name'    => $tmp,
            'error'       => $error,
            'size'        => $size,
        ];
    }
}
