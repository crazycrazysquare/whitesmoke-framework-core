<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Http\Response;

final class ResponseTest extends TestCase
{
    public function testFactories(): void
    {
        $this->assertSame(200, Response::html('<p>x</p>')->status());
        $this->assertSame(201, Response::html('x', 201)->status());
        $this->assertSame('ok', Response::text('ok')->body());
        $this->assertSame('{"id":1,"name":"Ä"}', Response::json(['id' => 1, 'name' => 'Ä'])->body());
        $this->assertSame(302, Response::redirect('/home')->status());
        $this->assertSame(303, Response::redirect('/home', 303)->status());
    }

    public function testLocalRedirectsAllowed(): void
    {
        foreach (['/', '/a/b?x=1', '/path#frag'] as $to) {
            $this->assertSame(302, Response::redirect($to)->status());
        }
    }

    public function testExternalRedirectsRejected(): void
    {
        $bad = ['https://evil.example', '//evil.example', '/\\evil.example', 'evil.example', 'javascript:alert(1)',
                "/\t/evil.example", "/\n/evil.example", "/\r/evil.example", '/ /evil.example', "/\x0b/evil.example", "/\x00/x", "/\x7f/x"];

        foreach ($bad as $to) {
            try {
                Response::redirect($to);
                $this->fail("Redirect to {$to} should be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHeaderInjectionRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::text('x')->header('X-Test', "ok\r\nSet-Cookie: evil=1");
    }

    public function testHeaderNameInjectionRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::text('x')->header("X-A\nX-B", 'v');
    }

    public function testSecurityHeadersPresent(): void
    {
        $h = Response::html('x')->headers();

        foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Content-Security-Policy', 'Cross-Origin-Opener-Policy', 'Permissions-Policy'] as $name) {
            $this->assertArrayHasKey($name, $h);
        }
    }

    public function testAppHeadersOverrideDefaultsCaseInsensitively(): void
    {
        $h = Response::html('x')->header('content-security-policy', 'default-src https:')->headers();

        $csp = array_filter($h, fn (string $k): bool => strtolower($k) === 'content-security-policy', ARRAY_FILTER_USE_KEY);
        $this->assertSame(['content-security-policy' => 'default-src https:'], $csp);
    }

    public function testHstsOnlyOverHttps(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        $this->assertArrayNotHasKey('Strict-Transport-Security', Response::html('x')->headers());

        $_SERVER['HTTPS'] = 'on';
        $this->assertSame('max-age=31536000', Response::html('x')->headers()['Strict-Transport-Security']);

        $_SERVER['HTTPS'] = 'off';
        $this->assertArrayNotHasKey('Strict-Transport-Security', Response::html('x')->headers());

        unset($_SERVER['HTTPS']);
    }
}
