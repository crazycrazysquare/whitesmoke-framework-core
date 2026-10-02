<?php
declare(strict_types=1);

namespace Whitesmoke\Cache;

/** Where Cache keeps entries. Keys are already validated; payloads are JSON strings. */
interface Store
{
    /** The payload, or null when missing or expired (expired entries are removed). */
    public function get(string $key): ?string;

    /** Store a payload; $expires is a Unix time, or 0 for no expiry. */
    public function put(string $key, string $payload, int $expires): void;

    public function delete(string $key): bool;

    /** Remove every entry; returns how many were removed. */
    public function clear(): int;
}
