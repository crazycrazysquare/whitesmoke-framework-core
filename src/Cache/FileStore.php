<?php
declare(strict_types=1);

namespace Whitesmoke\Cache;

use InvalidArgumentException;
use RuntimeException;

/**
 * One file per entry in a directory of its own (storage/cache/data), named by the
 * SHA-256 of the key. Writes go to a temporary file first and are then renamed, so
 * readers never see half-written entries. For a single server.
 */
final class FileStore implements Store
{
    public function __construct(private readonly string $path)
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid cache directory');
        }
    }

    public function get(string $key): ?string
    {
        $file = $this->file($key);
        $raw  = is_file($file) ? @file_get_contents($file) : false;

        if ($raw === false) {
            return null;
        }

        [$expires, $payload] = explode("\n", $raw, 2) + ['', ''];

        if (!ctype_digit($expires) || ((int) $expires !== 0 && (int) $expires <= time())) {
            @unlink($file);
            return null;
        }

        return $payload;
    }

    public function put(string $key, string $payload, int $expires): void
    {
        $file = $this->file($key);
        $dir  = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create cache directory {$dir}");
        }

        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($tmp, $expires . "\n" . $payload, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write cache file in {$dir}");
        }
        @chmod($tmp, 0640);

        // On Windows the rename fails while another process reads or replaces the same
        // entry. The temporary file was written, so the directory is writable: after a few
        // short retries, give up quietly. For a cache, losing to a simultaneous write of
        // the same key is the same as being overwritten a moment later.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (@rename($tmp, $file)) {
                return;
            }
            usleep(1000 << $attempt);
        }

        @unlink($tmp);
    }

    public function delete(string $key): bool
    {
        $file = $this->file($key);

        return is_file($file) && @unlink($file);
    }

    public function clear(): int
    {
        $count = 0;

        foreach (glob(rtrim($this->path, '/\\') . '/*/*.cache') ?: [] as $file) {
            $count += @unlink($file) ? 1 : 0;
        }

        return $count;
    }

    private function file(string $key): string
    {
        $hash = hash('sha256', $key);

        return rtrim($this->path, '/\\') . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }
}
