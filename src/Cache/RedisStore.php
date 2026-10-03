<?php
declare(strict_types=1);

namespace Whitesmoke\Cache;

use InvalidArgumentException;
use RuntimeException;

/**
 * Entries in Redis, through a small built-in client (no PHP extension needed).
 *
 * Encryption is "tls" (certificate always verified) or "none"; "none" is refused
 * unless Redis runs on this machine, so cached data and the password never cross a
 * network unencrypted. Every key starts with $namespace, and clear() removes only
 * those keys (SCAN + DEL), never the whole database.
 */
final class RedisStore implements Store
{
    private const CRYPTO = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly string $encryption = 'none',
        private readonly string $password = '',
        private readonly string $username = '',
        private readonly int $database = 0,
        private readonly int $timeout = 5,
        private readonly string $namespace = 'whitesmoke:',
    ) {
        if (!preg_match('~^(?:[A-Za-z0-9.-]+|\[?[0-9A-Fa-f:.]+\]?)\z~', $host)) {
            throw new InvalidArgumentException('Invalid Redis host');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid Redis port');
        }
        if (!in_array($encryption, ['tls', 'none'], true)) {
            throw new InvalidArgumentException('Redis encryption must be tls or none');
        }
        if ($encryption === 'none' && !self::isLocal($host)) {
            throw new InvalidArgumentException('Redis without encryption is only allowed on this machine; use tls');
        }
        if ($database < 0 || $database > 15 || $timeout < 1) {
            throw new InvalidArgumentException('Invalid Redis database or timeout');
        }
        if (!preg_match('~^[A-Za-z0-9_.:-]{1,64}\z~', $namespace) || preg_match('~[\r\n\0]~', $password . $username)) {
            throw new InvalidArgumentException('Invalid Redis namespace or credentials');
        }
    }

    public function __destruct()
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
        }
    }

    public function get(string $key): ?string
    {
        $value = $this->command('GET', $this->namespace . $key);

        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $payload, int $expires): void
    {
        if ($expires === 0) {
            $this->command('SET', $this->namespace . $key, $payload);
            return;
        }

        $seconds = $expires - time();

        if ($seconds < 1) {
            $this->delete($key);
            return;
        }

        $this->command('SET', $this->namespace . $key, $payload, 'EX', (string) $seconds);
    }

    public function delete(string $key): bool
    {
        return $this->command('DEL', $this->namespace . $key) > 0;
    }

    public function clear(): int
    {
        $cursor = '0';
        $count  = 0;

        do {
            [$cursor, $keys] = $this->command('SCAN', $cursor, 'MATCH', $this->namespace . '*', 'COUNT', '500');
            if ($keys !== []) {
                $count += (int) $this->command('DEL', ...$keys);
            }
        } while ($cursor !== '0');

        return $count;
    }

    private function connect(): void
    {
        $host    = str_contains($this->host, ':') && $this->host[0] !== '[' ? "[{$this->host}]" : $this->host;
        $scheme  = $this->encryption === 'tls' ? 'tls' : 'tcp';
        $context = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            'peer_name'         => trim($this->host, '[]'),
            'SNI_enabled'       => true,
            'crypto_method'     => self::CRYPTO,
        ]]);

        $socket = @stream_socket_client("{$scheme}://{$host}:{$this->port}", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new RuntimeException("Cannot connect to Redis at {$this->host}:{$this->port}: " . ($error !== '' ? $error : 'connection or TLS handshake failed'));
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        if ($this->password !== '') {
            $this->command(...($this->username === '' ? ['AUTH', $this->password] : ['AUTH', $this->username, $this->password]));
        }
        if ($this->database !== 0) {
            $this->command('SELECT', (string) $this->database);
        }
    }

    /** Send one command (RESP) and return the reply. Redis errors throw, without the command. */
    private function command(string ...$args): mixed
    {
        if ($this->socket === null) {
            $this->connect();
        }

        $out = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $out .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        if (@fwrite($this->socket, $out) !== strlen($out)) {
            $this->fail('Writing to Redis failed');
        }

        return $this->reply();
    }

    private function reply(): mixed
    {
        $line = fgets($this->socket);

        if ($line === false) {
            $this->fail((stream_get_meta_data($this->socket)['timed_out'] ?? false) ? 'Redis timed out' : 'Redis closed the connection');
        }

        $type = $line[0];
        $data = substr($line, 1, -2);

        return match ($type) {
            '+'     => $data,
            '-'     => throw new RuntimeException('Redis error: ' . $data),
            ':'     => (int) $data,
            '$'     => (int) $data < 0 ? null : $this->read((int) $data),
            '*'     => (int) $data < 0 ? null : $this->items((int) $data),
            default => $this->fail('Unexpected reply from Redis'),
        };
    }

    /** The $count replies of an array reply. */
    private function items(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->reply();
        }

        return $items;
    }

    /** A bulk string of $length bytes plus its CRLF. */
    private function read(int $length): string
    {
        $data = '';
        while (strlen($data) < $length + 2) {
            $chunk = fread($this->socket, $length + 2 - strlen($data));
            if ($chunk === false || $chunk === '') {
                $this->fail('Redis closed the connection');
            }
            $data .= $chunk;
        }

        return substr($data, 0, $length);
    }

    private function fail(string $message): never
    {
        @fclose($this->socket);
        $this->socket = null;
        throw new RuntimeException($message);
    }

    private static function isLocal(string $host): bool
    {
        $host = trim($host, '[]');

        if (strtolower($host) === 'localhost') {
            return true;
        }

        $bin = @inet_pton($host);

        return $bin !== false && ($bin === inet_pton('::1') || (strlen($bin) === 4 && $bin[0] === "\x7F"));
    }
}
