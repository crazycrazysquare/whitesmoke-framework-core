<?php
declare(strict_types=1);

namespace Whitesmoke\Security;

final class Throttle
{
    public function __construct(private readonly string $table = 'throttle') {}

    public function tooMany(string $key): bool
    {
        return $this->availableIn($key) > 0;
    }

    public function availableIn(string $key): int
    {
        $row = $this->find($key);

        return $row === null ? 0 : max(0, (int) $row['locked_until'] - time());
    }

    public function hit(string $key, int $max, int $decay): int
    {
        $now = time();
        $row = $this->find($key);

        if ($row === null) {
            $attempts   = 1;
            $windowEnds = $now + $decay;
            table($this->table)->insert([
                'throttle_key' => $key,
                'attempts'     => $attempts,
                'window_ends'  => $windowEnds,
                'locked_until' => 0,
            ]);
        } else {
            $expired    = (int) $row['window_ends'] < $now;
            $attempts   = $expired ? 1 : (int) $row['attempts'] + 1;
            $windowEnds = $expired ? $now + $decay : (int) $row['window_ends'];
        }

        $lockedUntil = $attempts >= $max ? $now + $decay : 0;

        table($this->table)->where('throttle_key', '=', $key)->update([
            'attempts'     => $lockedUntil > 0 ? 0 : $attempts,
            'window_ends'  => $lockedUntil > 0 ? $lockedUntil : $windowEnds,
            'locked_until' => $lockedUntil,
        ]);

        if (random_int(1, 100) === 1) {
            $this->prune();
        }

        return $attempts;
    }

    public function clear(string $key): void
    {
        table($this->table)->where('throttle_key', '=', $key)->delete();
    }

    private function find(string $key): ?array
    {
        return table($this->table)->where('throttle_key', '=', $key)->first();
    }

    private function prune(): void
    {
        $now = time();
        table($this->table)
            ->where('window_ends', '<', $now)
            ->where('locked_until', '<', $now)
            ->delete();
    }
}
