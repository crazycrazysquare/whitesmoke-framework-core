<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whitesmoke\Log\Logger;

final class LoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = WS_TEST_TMP . '/logs-' . bin2hex(random_bytes(4));
    }

    private function contents(): string
    {
        $files = glob($this->dir . '/whitesmoke-*.log') ?: [];
        return $files ? (string) file_get_contents($files[0]) : '';
    }

    public function testWritesDailyFileWithLevelAndContext(): void
    {
        (new Logger($this->dir))->info('Report exported', ['id' => 42]);

        $this->assertFileExists($this->dir . '/whitesmoke-' . date('Y-m-d') . '.log');
        $this->assertMatchesRegularExpression('~^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] INFO: Report exported \{"id":42\}\n$~', $this->contents());
    }

    public function testRespectsMinimumLevel(): void
    {
        $log = new Logger($this->dir, 'warning');
        $log->debug('d');
        $log->info('i');
        $log->warning('w');
        $log->error('e');

        $this->assertSame(2, substr_count($this->contents(), "\n"));
        $this->assertStringNotContainsString('INFO', $this->contents());
    }

    public function testRedactsSecretsAtAnyDepth(): void
    {
        (new Logger($this->dir))->info('x', ['password' => 'hunter2', 'nested' => ['_token' => 'abc', 'API_KEY' => 'k'], 'ok' => 1]);

        $line = $this->contents();
        $this->assertStringNotContainsString('hunter2', $line);
        $this->assertStringNotContainsString('abc', $line);
        $this->assertStringNotContainsString('"k"', $line);
        $this->assertSame(3, substr_count($line, '[redacted]'));
    }

    public function testNewlinesCannotForgeEntries(): void
    {
        (new Logger($this->dir))->warning("one\r\n[2026-01-01 00:00:00] ERROR: forged");

        $this->assertSame(1, substr_count($this->contents(), "\n"));
    }

    public function testExpandsExceptions(): void
    {
        (new Logger($this->dir))->error('failed', ['exception' => new RuntimeException('boom')]);

        $this->assertStringContainsString('RuntimeException: boom at ', $this->contents());
    }

    public function testPrunesOldFiles(): void
    {
        mkdir($this->dir, 0750, true);
        touch($this->dir . '/whitesmoke-2000-01-01.log');
        touch($this->dir . '/whitesmoke-' . date('Y-m-d', strtotime('-3 days')) . '.log');

        (new Logger($this->dir, 'info', 14))->info('new day');

        $this->assertFileDoesNotExist($this->dir . '/whitesmoke-2000-01-01.log');
        $this->assertFileExists($this->dir . '/whitesmoke-' . date('Y-m-d', strtotime('-3 days')) . '.log');
    }

    public function testInvalidLevelThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Logger($this->dir, 'verbose');
    }
}
