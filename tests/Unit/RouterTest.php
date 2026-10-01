<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Whitesmoke\Http\MethodNotAllowed;
use Whitesmoke\Routing\Router;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router([
            'GET /'              => ['Home', 'index'],
            'GET /reports'       => ['Reports', 'index', ['auth']],
            'POST /reports/save' => ['Reports', 'save', ['auth', 'csrf']],
        ]);
    }

    public function testMatchesStaticRoutes(): void
    {
        $this->assertSame(['Home', 'index'], $this->router->match('GET', '/'));
        $this->assertSame(['Reports', 'index', ['auth']], $this->router->match('GET', '/reports'));
        $this->assertSame(['Reports', 'save', ['auth', 'csrf']], $this->router->match('POST', '/reports/save'));
    }

    public function testTrailingSlashIgnored(): void
    {
        $this->assertNotNull($this->router->match('GET', '/reports/'));
        $this->assertNotNull($this->router->match('GET', 'reports'));
    }

    public function testHeadUsesGetRoute(): void
    {
        $this->assertSame(['Home', 'index'], $this->router->match('HEAD', '/'));
    }

    public function testUnknownPathReturnsNull(): void
    {
        $this->assertNull($this->router->match('GET', '/nope'));
        $this->assertNull($this->router->match('POST', '/reports'), 'GET-only path does not match POST');
    }

    public function testOtherMethodsRejectedWithAllowList(): void
    {
        foreach (['PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $method) {
            try {
                $this->router->match($method, '/');
                $this->fail("{$method} should be rejected");
            } catch (MethodNotAllowed $e) {
                $this->assertSame(['GET', 'POST'], $e->allowed);
                $this->assertSame(405, $e->status());
            }
        }
    }
}
