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
        foreach (['https://evil.example', '//evil.example', '/\\evil.example', 'evil.example', 'javascript:alert(1)'] as $to) {
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
}
