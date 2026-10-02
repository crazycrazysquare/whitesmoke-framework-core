<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Runs PHP's built-in server with the "php smoke serve" router against a
 * throwaway public/ folder and sends raw requests to it.
 */
final class ServeRouterTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $public = '';

    public static function setUpBeforeClass(): void
    {
        self::$public = WS_TEST_TMP . '/serve-public';
        @mkdir(self::$public . '/sub', 0700, true);
        file_put_contents(self::$public . '/index.php', "<?php http_response_code(404); echo 'FRONT';");
        file_put_contents(self::$public . '/style.css', 'body{color:#333}');
        file_put_contents(self::$public . '/.secret', 'SECRET');
        file_put_contents(self::$public . '/sub/.env', 'SECRET');
        file_put_contents(self::$public . '/probe.php', "<?php echo 'EXECUTED'; // SECRET");

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $router = dirname(__DIR__, 2) . '/src/Console/server.php';
        $log    = ['file', WS_TEST_TMP . '/serve.log', 'a'];

        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$public, $router],
            [0 => ['pipe', 'r'], 1 => $log, 2 => $log],
            $pipes
        ) ?: null;

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
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

    /** @return array{0: int, 1: string} status and body */
    private function get(string $path): array
    {
        $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 5);
        if ($socket === false) {
            $this->fail("Built-in server did not start: {$errstr}");
        }

        fwrite($socket, "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $body] = explode("\r\n\r\n", $raw, 2) + ['', ''];

        return [(int) substr($head, 9, 3), $body];
    }

    private function assertRoutedToFrontController(string $path): void
    {
        [$status, $body] = $this->get($path);

        $this->assertSame(404, $status, $path);
        $this->assertSame('FRONT', $body, $path);
    }

    public function testServesStaticFiles(): void
    {
        $this->assertSame([200, 'body{color:#333}'], $this->get('/style.css'));
    }

    public function testNeverServesDotfiles(): void
    {
        foreach (['/.secret', '/sub/.env', '/%2esecret', '/%5c.secret', '/sub%5c.env', '/sub/%5c.env'] as $path) {
            $this->assertRoutedToFrontController($path);
        }
    }

    public function testNeverServesOrRunsPhpFiles(): void
    {
        foreach (['/probe.php', '/PROBE.PHP', '/probe.php.', '/probe.php%20', '/probe.php::$DATA'] as $path) {
            $this->assertRoutedToFrontController($path);
        }
    }

    public function testNeverServesFilesOutsidePublic(): void
    {
        foreach (['/../serve.log', '/..%2fserve.log', '/..%5cserve.log', '/sub/..%5c..%5cserve.log'] as $path) {
            $this->assertRoutedToFrontController($path);
        }
    }

    public function testChecksDotfilesOnTheResolvedPath(): void
    {
        $alias = $this->dotfileAlias();

        if ($alias === null) {
            $this->markTestSkipped('No 8.3 short names or symlinks on this system.');
        }

        $this->assertRoutedToFrontController('/' . $alias);
    }

    /** A name without a leading dot that resolves to public/.secret. */
    private function dotfileAlias(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $file = str_replace('/', '\\', self::$public . '/.secret');
            $short = basename(trim((string) shell_exec('for %I in ("' . $file . '") do @echo %~sI')));

            return $short !== '' && $short !== '.secret' ? $short : null;
        }

        return @symlink(self::$public . '/.secret', self::$public . '/alias.txt') ? 'alias.txt' : null;
    }
}
