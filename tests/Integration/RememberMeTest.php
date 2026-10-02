<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Auth\RememberMe;

/** Browsers with cookie jars against tests/Fixtures/remember-server.php on the built-in server. */
final class RememberMeTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => WS_TEST_TMP . '/remember.sqlite', 'WS_TEST_TMP' => WS_TEST_TMP] + getenv();
        $log = ['file', WS_TEST_TMP . '/remember-server.log', 'a'];

        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/Fixtures/remember-server.php'],
            [0 => ['pipe', 'r'], 1 => $log, 2 => $log],
            $pipes,
            null,
            $env
        ) ?: null;

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    protected function setUp(): void
    {
        $none = [];
        $this->assertSame('ok', $this->get($none, '/setup')[0]);
    }

    /**
     * GET $path with the cookies in $jar; updates $jar like a browser.
     *
     * @return array{0: string, 1: list<string>} body, raw Set-Cookie values
     */
    private function get(array &$jar, string $path): array
    {
        $socket = fsockopen('127.0.0.1', self::$port, $errno, $error, 5);
        $cookie = $jar === [] ? '' : 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($jar), $jar)) . "\r\n";
        fwrite($socket, "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\n{$cookie}Connection: close\r\n\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $body] = explode("\r\n\r\n", $raw, 2) + ['', ''];
        preg_match_all('~^Set-Cookie: (.+)$~mi', $head, $m);
        $set = array_map('trim', $m[1]);

        foreach ($set as $line) {
            [$pair] = explode(';', $line, 2);
            [$name, $value] = explode('=', $pair, 2);
            if ($value === '' || $value === 'deleted' || stripos($line, 'Max-Age=0') !== false) {
                unset($jar[$name]);
            } else {
                $jar[$name] = $value;
            }
        }

        return [$body, $set];
    }

    private function rememberedBrowser(): array
    {
        $jar = [];
        $this->get($jar, '/login?remember=1');
        return ['ws_remember' => $jar['ws_remember']];   // only the long-lived cookie: the session is gone
    }

    private function tokens(): array
    {
        $none = [];
        return json_decode($this->get($none, '/tokens')[0], true);
    }

    public function testRememberedLoginSurvivesTheEndOfTheSession(): void
    {
        $jar = [];
        [, $set] = $this->get($jar, '/login?remember=1');

        $cookie = (string) current(array_filter($set, fn (string $l): bool => str_starts_with($l, 'ws_remember=')));
        $this->assertMatchesRegularExpression('~^ws_remember=[0-9a-f]{24}%3A[0-9a-f]{64};~', $cookie);
        $this->assertStringContainsString('path=/', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
        $this->assertMatchesRegularExpression('~Max-Age=(259199[0-9]|2592000)~', $cookie, '30 days');

        $later = ['ws_remember' => $jar['ws_remember']];
        $this->assertSame('1', $this->get($later, '/whoami')[0], 'logged back in from the cookie');
        $this->assertArrayHasKey('ws_test', $later, 'with a new session');
        $this->assertSame('1', $this->get($later, '/whoami')[0]);
    }

    public function testNoCookieWithoutRememberMe(): void
    {
        $jar = [];
        [, $set] = $this->get($jar, '/login');

        $this->assertSame([], array_filter($set, fn (string $l): bool => str_starts_with($l, 'ws_remember=')));
        unset($jar['ws_test']);
        $this->assertSame('guest', $this->get($jar, '/whoami')[0]);
    }

    public function testOnlyAHashOfTheSecretIsStored(): void
    {
        $browser = $this->rememberedBrowser();
        [$selector, $validator] = explode(':', rawurldecode($browser['ws_remember']));
        $row = $this->tokens()[0];

        $this->assertSame($selector, $row['selector']);
        $this->assertSame(hash('sha256', $validator), $row['validator_hash']);
        $this->assertStringNotContainsString($validator, json_encode($row));
    }

    public function testWrongSecretRemovesEveryRememberedLoginOfTheUser(): void
    {
        $laptop = $this->rememberedBrowser();
        $this->rememberedBrowser();
        $this->assertCount(2, $this->tokens());

        [$selector] = explode(':', rawurldecode($laptop['ws_remember']));
        $forged = ['ws_remember' => rawurlencode($selector . ':' . str_repeat('0', 64))];

        $this->assertSame('guest', $this->get($forged, '/whoami')[0]);
        $this->assertArrayNotHasKey('ws_remember', $forged, 'cookie removed');
        $this->assertSame([], $this->tokens(), 'both devices are forgotten');
        $this->assertSame('guest', $this->get($laptop, '/whoami')[0]);
    }

    public function testMalformedUnknownAndExpiredCookiesAreRemoved(): void
    {
        foreach (['abc', rawurlencode(str_repeat('a', 24) . ':' . str_repeat('b', 64)), rawurlencode(str_repeat('A', 24) . ':' . str_repeat('b', 64))] as $value) {
            $jar = ['ws_remember' => $value];
            $this->assertSame('guest', $this->get($jar, '/whoami')[0], $value);
            $this->assertArrayNotHasKey('ws_remember', $jar, $value);
        }

        $browser = $this->rememberedBrowser();
        $none = [];
        $this->get($none, '/expire');
        $this->assertSame('guest', $this->get($browser, '/whoami')[0]);
        $this->assertSame([], $this->tokens(), 'expired token deleted');
    }

    public function testPasswordChangeRevokesRememberedLogins(): void
    {
        $browser = $this->rememberedBrowser();
        $none = [];
        $this->get($none, '/password');

        $this->assertSame('guest', $this->get($browser, '/whoami')[0]);
        $this->assertSame([], $this->tokens());
    }

    public function testLogoutForgetsOnlyThisDevice(): void
    {
        $laptop = $this->rememberedBrowser();
        $phone  = $this->rememberedBrowser();

        $this->assertSame('1', $this->get($laptop, '/whoami')[0]);
        $this->get($laptop, '/logout');

        $this->assertArrayNotHasKey('ws_remember', $laptop);
        $this->assertSame('guest', $this->get($laptop, '/whoami')[0]);
        $this->assertCount(1, $this->tokens());
        $this->assertSame('1', $this->get($phone, '/whoami')[0], 'the phone stays remembered');
    }

    public function testCookieNameAndSettings(): void
    {
        $this->assertSame('__Host-ws_remember', (new RememberMe(30, cookie: ['secure' => true]))->cookieName());
        $this->assertSame('ws_remember', (new RememberMe(30, cookie: ['secure' => false]))->cookieName());

        foreach ([fn () => new RememberMe(0, cookie: []), fn () => new RememberMe(366, cookie: []), fn () => new RememberMe(30, 'tokens; DROP', cookie: []), fn () => new RememberMe(30, cookie: ['samesite' => 'None'])] as $bad) {
            try {
                $bad();
                $this->fail('Should refuse');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
