<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Regression test: "php smoke serve" built a shell command with escapeshellarg(), which
 * on Windows turns %, ! and " into spaces, so the server could not start for projects in
 * folders like "100% done!". It now starts PHP with an argument list.
 */
final class ServeCommandTest extends TestCase
{
    /** @var resource|null */
    private $process = null;
    private int $port = 0;

    protected function tearDown(): void
    {
        if ($this->process === null) {
            return;
        }

        $pid = (int) proc_get_status($this->process)['pid'];

        // "serve" starts the PHP server as a child process: stop both.
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /T /PID {$pid} 2>NUL");
        } else {
            foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
                $cmd = (string) @file_get_contents($file);
                if (str_contains($cmd, "127.0.0.1:{$this->port}\0")) {
                    @exec('kill ' . (int) basename(dirname($file)));
                }
            }
        }

        proc_terminate($this->process);
        proc_close($this->process);
        $this->process = null;
    }

    public function testServesAppsInFoldersWithSpecialCharacters(): void
    {
        $app = WS_TEST_TMP . '/100% done! & co^/app';
        @mkdir($app . '/public', 0700, true);
        file_put_contents($app . '/public/index.php', '<?php echo "served from " . __DIR__;');

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $log = ['file', WS_TEST_TMP . '/serve-command.log', 'a'];
        $this->process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/serve-entry.php', $app, (string) $this->port],
            [0 => ['pipe', 'r'], 1 => $log, 2 => $log],
            $pipes
        ) ?: null;

        $body = null;
        for ($i = 0; $i < 50 && $body === null; $i++) {
            usleep(100_000);
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
            if ($socket !== false) {
                fwrite($socket, "GET / HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
                $body = explode("\r\n\r\n", (string) stream_get_contents($socket), 2)[1] ?? '';
                fclose($socket);
            }
        }

        $this->assertNotNull($body, 'server did not start: ' . @file_get_contents(WS_TEST_TMP . '/serve-command.log'));
        $this->assertSame('served from ' . realpath($app . '/public'), $body);
    }
}
