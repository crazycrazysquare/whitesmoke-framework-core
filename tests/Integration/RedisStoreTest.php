<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whitesmoke\Cache\Cache;
use Whitesmoke\Cache\RedisStore;

/**
 * Runs against a throwaway Redis named by REDIS_TEST_PORT (and REDIS_TEST_PASSWORD if it
 * needs one), never your normal Redis: the tests write and delete keys.
 */
final class RedisStoreTest extends TestCase
{
    private function port(): int
    {
        $port = (int) env('REDIS_TEST_PORT', 0);
        if ($port === 0) {
            $this->markTestSkipped('Set REDIS_TEST_PORT to a throwaway Redis on 127.0.0.1.');
        }
        return $port;
    }

    private function store(int $database = 0, string $namespace = 'whitesmoke-test:'): RedisStore
    {
        return new RedisStore('127.0.0.1', $this->port(), 'none', (string) env('REDIS_TEST_PASSWORD', ''), '', $database, 5, $namespace);
    }

    /** One raw command on a separate connection, as another program would send it. */
    private function raw(string ...$args): string
    {
        $socket = fsockopen('127.0.0.1', $this->port(), $errno, $error, 5);
        $send   = '';
        if ((string) env('REDIS_TEST_PASSWORD', '') !== '') {
            $pass  = (string) env('REDIS_TEST_PASSWORD');
            $send .= "*2\r\n\$4\r\nAUTH\r\n$" . strlen($pass) . "\r\n{$pass}\r\n";
        }
        $send .= '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $send .= '$' . strlen($arg) . "\r\n{$arg}\r\n";
        }
        fwrite($socket, $send);
        usleep(100_000);
        $reply = (string) fread($socket, 65536);
        fclose($socket);

        // Drop the "+OK" that answered AUTH.
        return (string) env('REDIS_TEST_PASSWORD', '') !== '' ? substr($reply, (int) strpos($reply, "\r\n") + 2) : $reply;
    }

    protected function tearDown(): void
    {
        if ((int) env('REDIS_TEST_PORT', 0) !== 0) {
            foreach ([0, 3] as $db) {
                $this->store($db)->clear();
            }
        }
    }

    public function testValuesRoundTripThroughCache(): void
    {
        $cache = new Cache($this->store());
        $value = ['name' => 'Grüße', 'n' => 42, 'f' => 1.0, 'ok' => false, 'none' => null, 'list' => [1, [2]]];

        $cache->set('user:1', $value, 300);

        $this->assertSame($value, $cache->get('user:1'));
        $this->assertTrue($cache->has('user:1'));
        $this->assertSame(1, (int) $cache->remember('n', null, fn () => 1));
        $this->assertSame(1, (int) $cache->remember('n', null, fn () => 2), 'computed once');
        $this->assertTrue($cache->delete('user:1'));
        $this->assertFalse($cache->delete('user:1'));
    }

    public function testRedisExpiresEntries(): void
    {
        $cache = new Cache($this->store());
        $cache->set('short', 'x', 1);
        $cache->set('long', 'y');

        $ttl = $this->raw('TTL', 'whitesmoke-test:short');
        $this->assertMatchesRegularExpression('~^:1\r\n~', $ttl, 'Redis holds the expiry');
        $this->assertSame(":-1\r\n", $this->raw('TTL', 'whitesmoke-test:long'), 'no expiry');

        sleep(2);
        $this->assertNull($cache->get('short'));
        $this->assertSame('y', $cache->get('long'));
    }

    public function testPayloadsWithProtocolCharactersAndLargeValues(): void
    {
        $store = $this->store();

        $tricky = "line\r\n\$5\r\n*2\r\n-ERR fake\r\n:1\r\n";
        $store->put('tricky', $tricky, 0);
        $this->assertSame($tricky, $store->get('tricky'));

        $big = str_repeat(random_bytes(1000), 1000);
        $store->put('big', $big, 0);
        $this->assertSame($big, $store->get('big'));

        $this->assertNull($store->get('missing'));
    }

    public function testClearRemovesOnlyOurKeys(): void
    {
        $store = $this->store();
        foreach (range(1, 1200) as $i) {
            $store->put("k{$i}", 'v', 0);
        }
        $this->raw('SET', 'other-app:session', 'keep me');

        $this->assertSame(1200, $store->clear(), 'all our keys, across several SCAN pages');
        $this->assertNull($store->get('k1'));
        $this->assertStringContainsString('keep me', $this->raw('GET', 'other-app:session'), 'another app\'s key survives');
        $this->raw('DEL', 'other-app:session');
    }

    public function testDatabasesAreSeparate(): void
    {
        $this->store(0)->put('k', 'zero', 0);
        $this->store(3)->put('k', 'three', 0);

        $this->assertSame('zero', $this->store(0)->get('k'));
        $this->assertSame('three', $this->store(3)->get('k'));
    }

    public function testWrongPasswordFailsWithoutShowingIt(): void
    {
        if ((string) env('REDIS_TEST_PASSWORD', '') === '') {
            $this->markTestSkipped('Needs a Redis with a password (REDIS_TEST_PASSWORD).');
        }

        try {
            (new RedisStore('127.0.0.1', $this->port(), 'none', 'Wrong-Secret-42'))->get('k');
            $this->fail('A wrong password must fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Redis error', $e->getMessage());
            $this->assertStringNotContainsString('Wrong-Secret-42', $e->getMessage());
        }
    }

    public function testUsernameLogin(): void
    {
        if ((string) env('REDIS_TEST_USERNAME', '') === '') {
            $this->markTestSkipped('Needs Redis 6+ and REDIS_TEST_USERNAME.');
        }

        $store = new RedisStore('127.0.0.1', $this->port(), 'none', (string) env('REDIS_TEST_PASSWORD', ''), (string) env('REDIS_TEST_USERNAME'), 0, 5, 'whitesmoke-test:');
        $store->put('user-login', 'ok', 0);
        $this->assertSame('ok', $store->get('user-login'));
    }

    public function testUnencryptedRemoteRedisAndBadSettingsAreRefused(): void
    {
        foreach (['localhost', '127.0.0.1', '::1'] as $host) {
            new RedisStore($host, 6379, 'none');
        }

        $bad = [
            fn () => new RedisStore('redis.example.com', 6379, 'none'),
            fn () => new RedisStore('10.0.0.5', 6379, 'none'),
            fn () => new RedisStore("localhost\n", 6379, 'none'),
            fn () => new RedisStore('localhost', 0, 'none'),
            fn () => new RedisStore('localhost', 6379, 'ssl'),
            fn () => new RedisStore('localhost', 6379, 'none', "pw\r\nFLUSHALL"),
            fn () => new RedisStore('localhost', 6379, 'none', '', '', 16),
            fn () => new RedisStore('localhost', 6379, 'none', '', '', 0, 5, 'bad prefix*'),
        ];
        foreach ($bad as $i => $make) {
            try {
                $make();
                $this->fail("Case {$i} must be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUntrustedTlsCertificateIsRefused(): void
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $bundled = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (getenv('OPENSSL_CONF') === false && is_file($bundled)) {
            $config['config'] = $bundled;
        }
        $key = @openssl_pkey_new($config);
        $crt = $key === false ? false : @openssl_csr_sign(@openssl_csr_new(['commonName' => '127.0.0.1'], $key, $config), null, $key, 1, $config);
        if ($crt === false || !openssl_x509_export($crt, $pem) || !openssl_pkey_export($key, $keyPem, null, $config)) {
            $this->markTestSkipped('OpenSSL cannot create a test certificate here.');
        }
        $certFile = WS_TEST_TMP . '/redis-cert.pem';
        file_put_contents($certFile, $pem . $keyPem);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port  = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $log = WS_TEST_TMP . '/tls-server.log';
        touch($log);
        $server = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/tls-server.php', (string) $port, $certFile, $log], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port, $e, $s, 0.2) === false; $i++) {
                usleep(100_000);
            }
            file_put_contents($log, '');

            try {
                (new RedisStore('127.0.0.1', $port, 'tls', 'Secret-Password-1'))->get('k');
                $this->fail('A self-signed certificate must be refused');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Cannot connect to Redis', $e->getMessage());
            }

            usleep(300_000);
            $this->assertStringNotContainsString('received', (string) file_get_contents($log), 'nothing was sent, not even the password');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }
}
