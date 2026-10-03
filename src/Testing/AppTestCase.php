<?php
declare(strict_types=1);

namespace Whitesmoke\Testing;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whitesmoke\Database\Connection;
use Whitesmoke\Database\Migrations\Migrator;

/**
 * Base class for testing a Whitesmoke app through HTTP, as a browser would.
 *
 * Each test class starts the app on PHP's built-in server (the same router as
 * "php smoke serve"). The test run has its own SQLite database, migrated, and its own
 * storage folder (STORAGE_PATH) for sessions, logs, cache and uploads, in a temporary
 * folder removed at the end. Before every test the database, cache, uploads and mail
 * are emptied and cookies are forgotten. Emails are written to a log the test can read
 * (sentMail()). Your development database, storage and mail are never touched.
 *
 * The test process gets the same settings, so table() and db() in a test use the test
 * database. The folder stays the same for the whole run because db() keeps its
 * connection for the whole process.
 */
abstract class AppTestCase extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $dir = '';
    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> environment before setUpBeforeClass */
    private static array $saved = [];

    /** @var array<string, string> */
    private array $cookies = [];
    private ?string $token = null;

    /** The app's root folder. Default: BASE_PATH, defined in tests/bootstrap.php. */
    protected static function basePath(): string
    {
        return BASE_PATH;
    }

    /**
     * The app's settings in tests. .env is not read, so tests behave the same on every
     * machine. Add your own with: return ['MY_KEY' => 'value'] + parent::environment();
     */
    protected static function environment(): array
    {
        return [
            'IGNORE_DOTENV'     => 'true',
            'DB_CONNECTION'     => 'sqlite',
            'DB_DATABASE'       => self::$dir . '/database.sqlite',
            'STORAGE_PATH'      => self::$dir . '/storage',
            'CACHE_DRIVER'      => 'file',
            'MAIL_DRIVER'       => 'log',
            'MAIL_FROM_ADDRESS' => 'app@example.test',
            'SESSION_SECURE'    => 'false',
            'APP_DEBUG'         => 'true',
            'APP_URL'           => 'http://127.0.0.1:' . self::$port,
        ];
    }

    public static function setUpBeforeClass(): void
    {
        if (self::$dir === '') {
            self::removeOldRuns();
            self::$dir = rtrim(sys_get_temp_dir(), '/\\') . '/whitesmoke-app-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir(self::$dir . '/storage', 0700, true);
            register_shutdown_function(static fn () => self::remove(self::$dir));
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $env = static::environment();

        // The test process uses the same settings, so db() and table() in a test see the app's
        // data. tearDownAfterClass() puts the previous values back.
        self::$saved = [];
        foreach ($env as $key => $value) {
            self::$saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $migrations = static::basePath() . '/database/migrations';
        if (is_dir($migrations)) {
            (new Migrator(self::pdo(), $migrations))->migrate();
        }

        $log = ['file', self::$dir . '/server.log', 'a'];
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', static::basePath() . '/public', dirname(__DIR__) . '/Console/server.php'],
            [0 => ['pipe', 'r'], 1 => $log, 2 => $log],
            $pipes,
            static::basePath(),
            $env + getenv()
        ) ?: null;

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }

        throw new RuntimeException('The app did not start: ' . @file_get_contents(self::$dir . '/server.log'));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }

        foreach (self::$saved as $key => [$env, $envArray, $server]) {
            putenv($env === false ? $key : "{$key}={$env}");
            if ($envArray === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envArray;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }
        self::$saved = [];
    }

    protected function setUp(): void
    {
        // A clean start for every test: empty tables (the schema stays), no cookies, no mail.
        $pdo = self::pdo();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'migrations'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DELETE FROM "' . str_replace('"', '""', $table) . '"');
        }
        $pdo->exec('PRAGMA foreign_keys = ON');

        $this->cookies = [];
        $this->token   = null;

        self::remove(self::storagePath('cache/data'));
        self::remove(self::storagePath('uploads'));
        foreach (glob(self::storagePath('logs/mail-*.log')) ?: [] as $file) {
            @unlink($file);
        }
    }

    // ---------------------------------------------------------------- requests

    protected function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], $headers);
    }

    /**
     * POST a form. The CSRF token from the last page with a form is added; with none
     * known yet, the same URL (then /) is fetched first to get one.
     */
    protected function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        if (!isset($data['_token'])) {
            if ($this->token === null) {
                $this->get($uri);
            }
            if ($this->token === null) {
                $this->get('/');
            }
            if ($this->token !== null) {
                $data['_token'] = $this->token;
            }
        }

        return $this->request('POST', $uri, $data, $headers);
    }

    /** Log in through the login form; the response is the redirect after it. */
    protected function login(string $email, string $password, bool $remember = false): TestResponse
    {
        $this->get('/login');

        return $this->post('/login', ['email' => $email, 'password' => $password] + ($remember ? ['remember' => '1'] : []));
    }

    /** Forget the browser's cookies (as after closing it); pass names to forget only those. */
    protected function forgetCookies(string ...$names): void
    {
        $this->cookies = $names === [] ? [] : array_diff_key($this->cookies, array_flip($names));
        $this->token   = null;
    }

    /** @return array<string, string> the cookies the "browser" holds */
    protected function cookies(): array
    {
        return $this->cookies;
    }

    protected function request(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        if (!preg_match('~^/[^\s]*\z~', $uri)) {
            throw new \InvalidArgumentException('Request a local path such as /login');
        }

        $body = $method === 'GET' ? '' : http_build_query($data);
        $head = "{$method} {$uri} HTTP/1.0\r\nHost: 127.0.0.1:" . self::$port . "\r\nConnection: close\r\n";

        if ($this->cookies !== []) {
            $head .= 'Cookie: ' . implode('; ', array_map(fn (string $k, string $v): string => "{$k}={$v}", array_keys($this->cookies), $this->cookies)) . "\r\n";
        }
        if ($method !== 'GET') {
            $head .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n";
        }
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        $socket = fsockopen('127.0.0.1', self::$port, $errno, $error, 10) ?: throw new RuntimeException("Cannot reach the app: {$error}");
        stream_set_timeout($socket, 30);
        fwrite($socket, $head . "\r\n" . $body);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$rawHead, $content] = explode("\r\n\r\n", $raw, 2) + ['', ''];
        $lines   = explode("\r\n", $rawHead);
        $headers = [];

        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2) + ['', '']);
            $name = strtolower($name);

            if ($name === 'set-cookie') {
                $this->rememberCookie($value);
            }
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . "\n" . $value : $value;
        }

        if (preg_match('~name="_token" value="([0-9a-f]+)"~', $content, $m)) {
            $this->token = $m[1];
        }

        return new TestResponse((int) substr($lines[0], 9, 3), $headers, $content);
    }

    private function rememberCookie(string $line): void
    {
        [$pair] = explode(';', $line, 2);
        [$name, $value] = explode('=', $pair, 2) + ['', ''];

        // A new session (after login, say) has a new CSRF token: post() fetches it again.
        if (($this->cookies[$name] ?? null) !== $value) {
            $this->token = null;
        }

        if ($value === '' || $value === 'deleted' || preg_match('~;\s*Max-Age=0~i', $line)) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }

    // ---------------------------------------------------------------- database and mail

    /** A row in $table matches every column => value in $where. */
    protected function assertDatabaseHas(string $table, array $where): void
    {
        $this->assertGreaterThan(0, $this->countRows($table, $where), "No row in {$table} matches " . json_encode($where));
    }

    protected function assertDatabaseMissing(string $table, array $where): void
    {
        $this->assertSame(0, $this->countRows($table, $where), "A row in {$table} matches " . json_encode($where));
    }

    private function countRows(string $table, array $where): int
    {
        $query = (new \Whitesmoke\Database\Query(self::pdo(), $table));
        foreach ($where as $column => $value) {
            $query->where((string) $column, '=', $value);
        }

        return $query->count();
    }

    /**
     * Emails the app sent during this test, oldest first: to (every recipient),
     * subject, text (the plain text body) and raw.
     *
     * @return list<array{to: list<string>, subject: string, text: string, raw: string}>
     */
    protected function sentMail(): array
    {
        $mails = [];

        foreach (glob(self::storagePath('logs/mail-*.log')) ?: [] as $file) {
            foreach (preg_split('~^===== [^\r\n]+ =====\r\n~m', (string) file_get_contents($file), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
                [$envelope, $raw] = explode("\r\n\r\n", $entry, 2) + ['', ''];
                [$head, $body]    = explode("\r\n\r\n", $raw, 2) + ['', ''];

                preg_match('~^Subject: (.+?)\r\n(?! )~ms', $head, $subject);
                if (preg_match('~boundary="([^"]+)"~', $head . $body, $b) && preg_match('~Content-Type: text/plain; charset=UTF-8\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n--' . preg_quote($b[1], '~') . '~s', $body, $part)) {
                    $body = $part[1];
                }

                $mails[] = [
                    'to'      => array_map('trim', explode(',', substr($envelope, strlen('Envelope recipients: ')))),
                    'subject' => mb_decode_mimeheader(str_replace("\r\n ", ' ', $subject[1] ?? '')),
                    'text'    => str_replace("\r\n", "\n", quoted_printable_decode(rtrim($body, "\r\n"))),
                    'raw'     => $raw,
                ];
            }
        }

        return $mails;
    }

    /** A path in the app's storage folder for this test class (STORAGE_PATH), e.g. storagePath('uploads'). */
    protected static function storagePath(string $path = ''): string
    {
        return self::$dir . '/storage' . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    private static function pdo(): PDO
    {
        return Connection::make(['driver' => 'sqlite', 'database' => self::$dir . '/database.sqlite']);
    }

    /**
     * Folders of earlier runs, unused for an hour. On Windows a run cannot delete its own
     * database at the end while db() in the test process still has it open.
     */
    private static function removeOldRuns(): void
    {
        foreach (glob(rtrim(sys_get_temp_dir(), '/\\') . '/whitesmoke-app-test-*', GLOB_ONLYDIR) ?: [] as $old) {
            $used = @filemtime($old . '/database.sqlite') ?: @filemtime($old);

            if ($used !== false && $used < time() - 3600) {
                self::remove($old);
            }
        }
    }

    private static function remove(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
