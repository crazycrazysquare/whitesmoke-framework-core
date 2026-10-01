<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Whitesmoke\Http\BadRequest;
use Whitesmoke\Http\Request;

final class RequestTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = $_POST = $_FILES = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    private function capture(string $method, string $uri, array $get = [], array $post = [], array $files = []): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        $_GET   = $get;
        $_POST  = $post;
        $_FILES = $files;

        return Request::capture();
    }

    public function testMethodAndPathWithoutQueryString(): void
    {
        $r = $this->capture('get', '/reports/show?id=5');

        $this->assertSame('GET', $r->method());
        $this->assertSame('/reports/show', $r->path());
    }

    public function testPathIsUrlDecoded(): void
    {
        $this->assertSame('/a b', $this->capture('GET', '/a%20b')->path());
    }

    public function testGetAndPostReturnStringsOrDefault(): void
    {
        $r = $this->capture('POST', '/', ['q' => 'x', 'arr' => ['a']], ['title' => 'T']);

        $this->assertSame('x', $r->get('q'));
        $this->assertSame('d', $r->get('missing', 'd'));
        $this->assertSame('d', $r->get('arr', 'd'), 'arrays must not be returned as strings');
        $this->assertSame('T', $r->post('title'));
        $this->assertNull($r->post('nope'));
    }

    public function testIntReadersAcceptOnlyPositiveWholeNumbers(): void
    {
        $cases = ['42' => 42, '1' => 1, '0' => null, '-5' => null, '1abc' => null, '1 OR 1=1' => null, '4.5' => null, '' => null];

        foreach ($cases as $input => $expected) {
            $r = $this->capture('POST', '/', ['id' => (string) $input], ['id' => (string) $input]);
            $this->assertSame($expected, $r->getInt('id'), "getInt('{$input}')");
            $this->assertSame($expected, $r->postInt('id'), "postInt('{$input}')");
        }

        $this->assertNull($this->capture('GET', '/', ['id' => ['1']])->getInt('id'));
        $this->assertNull($this->capture('GET', '/')->getInt('id'));
    }

    public function testOnlyReturnsListedStringFields(): void
    {
        $r = $this->capture('POST', '/', [], ['name' => 'A', 'is_admin' => '1', 'tags' => ['x']]);

        $this->assertSame(['name' => 'A'], $r->only(['name', 'tags', 'absent']));
    }

    public function testNullBytesAreStripped(): void
    {
        $this->assertSame('ab', $this->capture('GET', '/', ['q' => "a\0b"])->get('q'));
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $this->expectException(BadRequest::class);
        $this->capture('GET', '/', ['q' => "\xC3\x28"]);
    }

    public function testInvalidUtf8InNestedArrayIsRejected(): void
    {
        $this->expectException(BadRequest::class);
        $this->capture('POST', '/', [], ['a' => ['b' => "\xFF"]]);
    }

    public function testSingleFileIsNormalized(): void
    {
        $r = $this->capture('POST', '/', [], [], ['avatar' => [
            'name' => '../../evil.php', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10,
        ]]);

        $file = $r->file('avatar');
        $this->assertSame('evil.php', $file['name'], 'directory parts must be removed');
        $this->assertSame('image/png', $file['client_type']);
        $this->assertNull($file['type'], 'type is only set for real uploaded files');
        $this->assertCount(1, $r->files('avatar'));
        $this->assertNull($r->file('missing'));
    }

    public function testMultipleFilesAreNormalizedPerFile(): void
    {
        $r = $this->capture('POST', '/', [], [], ['docs' => [
            'name'     => ['a.pdf', 'b.pdf'],
            'type'     => ['application/pdf', 'application/pdf'],
            'tmp_name' => ['/tmp/a', '/tmp/b'],
            'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
            'size'     => [1, 0],
        ]]);

        $files = $r->files('docs');
        $this->assertCount(2, $files);
        $this->assertSame('b.pdf', $files[1]['name']);
        $this->assertSame(UPLOAD_ERR_NO_FILE, $files[1]['error']);
    }
}
