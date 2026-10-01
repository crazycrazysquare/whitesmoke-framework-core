<?php
declare(strict_types=1);

namespace Whitesmoke\Http;

final class Request
{
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        private readonly array $server,
    ) {}

    public static function capture(): self
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
        );
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

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
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
