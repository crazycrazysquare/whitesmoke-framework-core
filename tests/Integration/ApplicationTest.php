<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whitesmoke\Foundation\Application;
use Whitesmoke\Http\MethodNotAllowed;
use Whitesmoke\Http\NotFound;
use Whitesmoke\Http\Request;
use Whitesmoke\Http\Response;
use Whitesmoke\Middleware\Csrf;
use Whitesmoke\Tests\Fixtures\PassMiddleware;

final class ApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    private function request(string $method, string $uri, array $post = []): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
        $_POST = $post;

        return Request::capture();
    }

    private function handle(string $method, string $uri, array $post = []): Response
    {
        return (new Application(BASE_PATH))->handle($this->request($method, $uri, $post));
    }

    public function testDispatchesToController(): void
    {
        $this->assertSame('home', $this->handle('GET', '/')->body());
        $this->assertSame('item 5', $this->handle('GET', '/item?id=5')->body());
        $this->assertSame(302, $this->handle('POST', '/item')->status());
    }

    public function testControllerCanThrowNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->handle('GET', '/item?id=abc');
    }

    public function testUnknownRouteIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->handle('GET', '/does-not-exist');
    }

    public function testUnsupportedMethod(): void
    {
        $this->expectException(MethodNotAllowed::class);
        $this->handle('DELETE', '/item');
    }

    public function testMiddlewareRunsInOrderAndCanStop(): void
    {
        PassMiddleware::$calls = 0;
        $response = $this->handle('GET', '/blocked');

        $this->assertSame(1, PassMiddleware::$calls, 'first middleware ran');
        $this->assertSame(403, $response->status());
        $this->assertSame('blocked', $response->body(), 'controller never ran');
    }

    public function testUnknownMiddlewareNameFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown middleware: missing');
        $this->handle('GET', '/typo');
    }

    public function testRunRendersErrorPagesWithoutLeakingDetails(): void
    {
        if (headers_sent()) {
            $this->markTestSkipped('Run PHPUnit with --stderr (composer test).');
        }

        $this->request('GET', '/does-not-exist');
        ob_start();
        (new Application(BASE_PATH))->run();
        $this->assertStringContainsString('<h1>404</h1>', (string) ob_get_clean());

        $this->request('GET', '/boom');
        ob_start();
        (new Application(BASE_PATH))->run();
        $body = (string) ob_get_clean();
        restore_error_handler();
        restore_error_handler();

        $this->assertStringContainsString('<h1>500</h1>', $body);
        $this->assertStringNotContainsString('secret internal detail', $body, 'details go to the log, not the page');

        $logs = glob(WS_TEST_TMP . '/logs/whitesmoke-*.log') ?: [];
        $this->assertNotEmpty($logs);
        $this->assertStringContainsString('secret internal detail', (string) file_get_contents($logs[0]));
    }

    public function testCsrfMiddleware(): void
    {
        if (headers_sent()) {
            $this->markTestSkipped('Run PHPUnit with --stderr (composer test).');
        }

        $csrf  = new Csrf();
        $token = session()->token();

        $this->assertNull($csrf->handle($this->request('GET', '/form')), 'GET is not checked');
        $this->assertSame(403, $csrf->handle($this->request('POST', '/form'))?->status(), 'missing token');
        $this->assertSame(403, $csrf->handle($this->request('POST', '/form', ['_token' => 'forged']))?->status(), 'wrong token');
        $this->assertSame(403, $csrf->handle($this->request('POST', '/form', ['_token' => ['x']]))?->status(), 'array token');
        $this->assertNull($csrf->handle($this->request('POST', '/form', ['_token' => $token])), 'valid token');

        session()->close();
    }

    /** respond() for GET / from $remote with the given TRUSTED_PROXIES and X-Forwarded-Proto. */
    private function respondBehindProxy(string $trusted, string $remote, ?string $proto): Response
    {
        $_ENV['TRUSTED_PROXIES'] = $trusted;
        $_SERVER['REMOTE_ADDR']  = $remote;
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        if ($proto !== null) {
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = $proto;
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/';

        try {
            return (new Application(BASE_PATH))->respond();
        } finally {
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        }
    }

    public function testHstsBehindATrustedHttpsProxy(): void
    {
        $hsts = fn (Response $r): bool => isset($r->headers()['Strict-Transport-Security']);

        $this->assertTrue($hsts($this->respondBehindProxy('10.0.0.0/8, 192.168.1.10', '10.0.0.5', 'https')));
        $this->assertFalse($hsts($this->respondBehindProxy('', '10.0.0.5', 'https')), 'no trusted proxies: header ignored');
        $this->assertFalse($hsts($this->respondBehindProxy('10.0.0.0/8', '198.51.100.9', 'https')), 'sender is not a trusted proxy');
        $this->assertFalse($hsts($this->respondBehindProxy('10.0.0.0/8', '10.0.0.5', 'http')));
    }

    public function testInvalidTrustedProxiesFailClosed(): void
    {
        $response = $this->respondBehindProxy('10.0.0.0/8, *', '10.0.0.5', 'https');

        $this->assertSame(500, $response->status());
        $this->assertArrayNotHasKey('Strict-Transport-Security', $response->headers());

        $logs = glob(WS_TEST_TMP . '/logs/whitesmoke-*.log') ?: [];
        $this->assertNotEmpty($logs);
        $this->assertStringContainsString('Invalid trusted proxy "*"', (string) file_get_contents($logs[0]));
    }
}
