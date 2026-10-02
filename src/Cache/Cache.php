<?php
declare(strict_types=1);

namespace Whitesmoke\Cache;

use Closure;
use InvalidArgumentException;
use JsonException;

/**
 * Key-value cache over a Store (files or a database table).
 *
 * Values are stored as JSON, never with serialize(), so a tampered cache entry can
 * never create PHP objects: strings, numbers, booleans, null and arrays of them
 * can be cached; objects are refused.
 */
final class Cache
{
    public function __construct(private readonly Store $store, private readonly string $prefix = '')
    {
        if ($prefix !== '') {
            self::check($prefix);
        }
    }

    /** The cached value, or $default when it is missing or expired. */
    public function get(string $key, mixed $default = null): mixed
    {
        $payload = $this->store->get($this->key($key));

        if ($payload === null) {
            return $default;
        }

        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['v'] ?? null;
        } catch (JsonException) {
            $this->store->delete($this->key($key));   // corrupt entry: treat as missing
            return $default;
        }
    }

    public function has(string $key): bool
    {
        return $this->store->get($this->key($key)) !== null;
    }

    /** Store $value for $seconds (null keeps it until deleted or cleared). */
    public function set(string $key, mixed $value, ?int $seconds = null): void
    {
        if ($seconds !== null && $seconds < 1) {
            throw new InvalidArgumentException('Cache time must be at least 1 second, or null for no expiry');
        }

        try {
            $payload = json_encode(['v' => self::plain($value)], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Cache value cannot be stored: ' . $e->getMessage(), 0, $e);
        }

        $this->store->put($this->key($key), $payload, $seconds === null ? 0 : time() + $seconds);
    }

    /** The cached value; on a miss, compute it with $make, store it for $seconds, and return it. */
    public function remember(string $key, ?int $seconds, Closure $make): mixed
    {
        $payload = $this->store->get($this->key($key));

        if ($payload !== null) {
            try {
                return json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['v'] ?? null;
            } catch (JsonException) {
                // corrupt entry: compute again below
            }
        }

        $value = $make();
        $this->set($key, $value, $seconds);

        return $value;
    }

    public function delete(string $key): bool
    {
        return $this->store->delete($this->key($key));
    }

    /** Remove every entry; returns how many were removed. */
    public function clear(): int
    {
        return $this->store->clear();
    }

    private function key(string $key): string
    {
        self::check($key);

        return $this->prefix === '' ? $key : $this->prefix . ':' . $key;
    }

    private static function check(string $key): void
    {
        if (!preg_match('~^[A-Za-z0-9_.:-]{1,150}\z~', $key)) {
            throw new InvalidArgumentException('Cache keys use 1 to 150 letters, digits and _ . : - characters');
        }
    }

    /** Only JSON-safe values: scalars, null and arrays of them. */
    private static function plain(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }
        if ($value === null || is_scalar($value)) {
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('Cache values cannot be INF or NAN');
            }
            return $value;
        }

        throw new InvalidArgumentException('Cache values must be strings, numbers, booleans, null or arrays of them, not ' . get_debug_type($value));
    }
}
