<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Storage\Uploads;

/**
 * Real multipart uploads to PHP's built-in server running tests/Fixtures/upload-server.php,
 * so is_uploaded_file() and move_uploaded_file() run exactly as in production.
 */
final class UploadsTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = WS_TEST_TMP . '/uploads';

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $log = ['file', WS_TEST_TMP . '/upload-server.log', 'a'];
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'upload_max_filesize=4K', '-d', 'post_max_size=1M', '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/Fixtures/upload-server.php'],
            [0 => ['pipe', 'r'], 1 => $log, 2 => $log],
            $pipes,
            null,
            ['WS_UPLOADS' => self::$dir] + getenv()
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

    /** @return array{0: int, 1: array<string, string>, 2: string} status, headers (lower-case names), body */
    private function http(string $method, string $target, string $body = '', string $contentType = ''): array
    {
        $socket = fsockopen('127.0.0.1', self::$port, $errno, $error, 5);
        $head = "{$method} {$target} HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
        if ($body !== '') {
            $head .= "Content-Type: {$contentType}\r\nContent-Length: " . strlen($body) . "\r\n";
        }
        fwrite($socket, $head . "\r\n" . $body);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$rawHead, $rawBody] = explode("\r\n\r\n", $raw, 2) + ['', ''];
        $lines   = explode("\r\n", $rawHead);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = explode(':', $line, 2) + ['', ''];
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [(int) substr($lines[0], 9, 3), $headers, $rawBody];
    }

    /** Upload $content as $filename in the "file" field; returns the decoded JSON reply. */
    private function upload(string $filename, string $content, string $query = 'types=png', string $endpoint = '/store'): array
    {
        $boundary = 'b' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\nContent-Type: application/octet-stream\r\n\r\n"
            . $content . "\r\n--{$boundary}--\r\n";

        [, , $reply] = $this->http('POST', "{$endpoint}?{$query}", $body, "multipart/form-data; boundary={$boundary}");

        return json_decode($reply, true) ?? ['raw' => $reply];
    }

    public function testStoresAValidFileUnderARandomId(): void
    {
        $png = (string) base64_decode(self::PNG);
        $result = $this->upload('photo.PNG', $png, 'types=png,jpg');

        $this->assertTrue($result['ok'] ?? false, json_encode($result));
        $this->assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $result['id']);
        $this->assertSame(['photo.PNG', 'image/png', strlen($png), 'png', true], [$result['name'], $result['type'], $result['size'], $result['extension'], $result['stored']]);
        $this->assertSame($png, file_get_contents((new Uploads(self::$dir))->path($result['id'])));
        $this->assertSame([], glob(self::$dir . '/*/*.png') ?: [], 'the visitor\'s name is never used on disk');
    }

    public function testFolderPartsOfTheNameAreDropped(): void
    {
        $result = $this->upload('..\\..\\evil\\Résumé final.pdf', self::PDF, 'types=pdf');

        $this->assertSame('Résumé final.pdf', $result['name'] ?? json_encode($result));
        $this->assertSame('application/pdf', $result['type']);
    }

    public function testDisguisedFilesAreRefused(): void
    {
        $cases = [
            ['shell.png', "<?php system(\$_GET['c']);", 'types=png'],
            ['page.txt', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'types=txt'],
            ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'types=png'],
            ['report.pdf', (string) base64_decode(self::PNG), 'types=pdf'],
        ];

        foreach ($cases as [$name, $content, $query]) {
            $result = $this->upload($name, $content, $query);
            $this->assertFalse($result['ok'] ?? true, $name);
            $this->assertStringContainsString("contents don't match its type", $result['error'] ?? '', $name);
        }
    }

    public function testRefusedTypesSizesAndEmptyFiles(): void
    {
        $png = (string) base64_decode(self::PNG);

        $this->assertSame('This type of file is not allowed. Allowed: png, jpg.', $this->upload('doc.pdf', self::PDF, 'types=png,jpg')['error'] ?? null);
        $this->assertSame('This type of file is not allowed. Allowed: png.', $this->upload('noext', $png)['error'] ?? null);
        $this->assertSame('The file is too large (at most 50 bytes).', $this->upload('photo.png', $png, 'types=png&max=50')['error'] ?? null);
        $this->assertSame('The file is too large (at most 1000 KB).', $this->upload('big.txt', str_repeat('a', 5000), 'types=txt&max=1024000')['error'] ?? null, 'over upload_max_filesize');
        $this->assertSame('The file is empty.', $this->upload('empty.txt', '', 'types=txt')['error'] ?? null);

        [, , $reply] = $this->http('POST', '/store?types=png', 'x=1', 'application/x-www-form-urlencoded');
        $this->assertSame('Choose a file to upload.', json_decode($reply, true)['error'] ?? null);
    }

    public function testFilesThatWereNotUploadedAreRefused(): void
    {
        [, , $reply] = $this->http('POST', '/store-fake', 'x=1', 'application/x-www-form-urlencoded');

        $this->assertSame('The upload failed. Try again.', json_decode($reply, true)['error'] ?? null, 'a file already on the server cannot be "stored"');
    }

    public function testUnknownTypesInCodeFailLoudly(): void
    {
        $result = $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'types=svg');

        $this->assertSame(InvalidArgumentException::class, $result['exception'] ?? null);
        $this->assertNotContains('svg', Uploads::types());
        $this->assertEmpty(array_intersect(['html', 'htm', 'php', 'phtml', 'js', 'exe'], Uploads::types()));
    }

    public function testDownloadSendsTheFileWithSafeHeaders(): void
    {
        $stored = $this->upload('report.pdf', self::PDF, 'types=pdf');
        $name   = "Résumé \"final\"\r\nX-Evil: 1.pdf";
        $query  = http_build_query(['id' => $stored['id'], 'name' => $name, 'type' => 'application/pdf']);

        [$status, $headers, $body] = $this->http('GET', "/download?{$query}");

        $this->assertSame(200, $status);
        $this->assertSame(self::PDF, $body);
        $this->assertSame((string) strlen(self::PDF), $headers['content-length']);
        $this->assertSame('application/pdf', $headers['content-type']);
        $this->assertArrayNotHasKey('x-evil', $headers, 'no header injection through the name');
        $this->assertSame(
            'attachment; filename="R_sum_ _final_X-Evil_ 1.pdf"; filename*=UTF-8\'\'' . rawurlencode("Résumé \"final\"X-Evil: 1.pdf"),
            $headers['content-disposition']
        );
        $this->assertStringContainsString('sandbox', $headers['content-security-policy']);
        $this->assertSame('nosniff', $headers['x-content-type-options']);
    }

    public function testOnlyImagesAndPdfsAreShownInline(): void
    {
        $pdf = $this->upload('a.pdf', self::PDF, 'types=pdf');
        $txt = $this->upload('a.txt', 'plain text', 'types=txt');

        [, $pdfHeaders] = $this->http('GET', '/download?' . http_build_query(['id' => $pdf['id'], 'name' => 'a.pdf', 'type' => 'application/pdf', 'inline' => '1']));
        [, $txtHeaders] = $this->http('GET', '/download?' . http_build_query(['id' => $txt['id'], 'name' => 'a.txt', 'type' => 'text/plain', 'inline' => '1']));
        [, $htmlHeaders] = $this->http('GET', '/download?' . http_build_query(['id' => $txt['id'], 'name' => 'a.html', 'type' => 'text/html', 'inline' => '1']));

        $this->assertStringStartsWith('inline;', $pdfHeaders['content-disposition']);
        $this->assertStringNotContainsString('sandbox', $pdfHeaders['content-security-policy'], 'Chrome will not show sandboxed PDFs');
        $this->assertStringStartsWith('attachment;', $txtHeaders['content-disposition']);
        $this->assertStringStartsWith('attachment;', $htmlHeaders['content-disposition'], 'never render HTML inline');
        $this->assertStringContainsString('sandbox', $htmlHeaders['content-security-policy']);
    }

    public function testBadOrUnknownIdsAreRefused(): void
    {
        [$status, , $reply] = $this->http('GET', '/download?' . http_build_query(['id' => '../../composer.json', 'name' => 'x', 'type' => 'text/plain']));
        $this->assertSame([500, InvalidArgumentException::class], [$status, json_decode($reply, true)['exception'] ?? null]);

        [$status] = $this->http('GET', '/download?' . http_build_query(['id' => str_repeat('a', 32), 'name' => 'x', 'type' => 'text/plain']));
        $this->assertSame(404, $status);

        $uploads = new Uploads(self::$dir);
        foreach (['', 'ABCDEF0123456789ABCDEF0123456789', str_repeat('a', 31), str_repeat('a', 32) . "\n", '../' . str_repeat('a', 29)] as $bad) {
            try {
                $uploads->path($bad);
                $this->fail('Should refuse ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDelete(): void
    {
        $stored  = $this->upload('a.txt', 'delete me', 'types=txt');
        $uploads = new Uploads(self::$dir);

        $this->assertTrue($uploads->exists($stored['id']));
        $this->assertTrue($uploads->delete($stored['id']));
        $this->assertFalse($uploads->exists($stored['id']));
        $this->assertFalse($uploads->delete($stored['id']));
    }
}
