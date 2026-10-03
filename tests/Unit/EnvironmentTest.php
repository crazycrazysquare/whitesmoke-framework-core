<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Whitesmoke\Foundation\Environment;

final class EnvironmentTest extends TestCase
{
    private string $dir;
    private array $keys = ['WS_T_NAME', 'WS_T_QUOTED', 'WS_T_BLANK', 'WS_T_BOOL', 'WS_T_REAL'];

    protected function setUp(): void
    {
        $this->dir = WS_TEST_TMP . '/env-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage/cache', 0700, true);
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        putenv('WS_T_REAL');
    }

    private function reset(): void
    {
        (new ReflectionProperty(Environment::class, 'loaded'))->setValue(null, false);

        foreach ($this->keys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function write(string $contents): void
    {
        file_put_contents($this->dir . '/.env', $contents);
    }

    public function testLoadsDotenvFile(): void
    {
        $this->write("WS_T_NAME=clinic\nWS_T_QUOTED=\"a b # c\"\nWS_T_BLANK=\nWS_T_BOOL=false # comment\n");
        Environment::load($this->dir);

        $this->assertSame('clinic', env('WS_T_NAME'));
        $this->assertSame('a b # c', env('WS_T_QUOTED'));
        $this->assertSame('fallback', env('WS_T_BLANK', 'fallback'), 'blank values use the default');
        $this->assertFalse(env('WS_T_BOOL'));
    }

    public function testRealEnvironmentWins(): void
    {
        putenv('WS_T_REAL=from-server');
        $this->write("WS_T_REAL=from-dotenv\n");
        Environment::load($this->dir);

        $this->assertSame('from-server', env('WS_T_REAL'));
    }

    public function testMissingFileIsFine(): void
    {
        Environment::load($this->dir);
        $this->assertNull(env('WS_T_NAME'));
    }

    public function testInvalidFileThrowsClearError(): void
    {
        $this->write("NOT VALID LINE\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.env is invalid');
        Environment::load($this->dir);
    }

    public function testCacheIsUsedInsteadOfFile(): void
    {
        $this->write("WS_T_NAME=cached\n");
        $this->assertSame(1, Environment::cache($this->dir));

        $this->write("WS_T_NAME=changed\n");
        Environment::load($this->dir);
        $this->assertSame('cached', env('WS_T_NAME'));

        $this->reset();
        $this->assertTrue(Environment::clear($this->dir));
        $this->assertFalse(Environment::clear($this->dir));
        Environment::load($this->dir);
        $this->assertSame('changed', env('WS_T_NAME'));
    }

    public function testIgnoreDotenvSkipsFileAndCache(): void
    {
        try {
            $this->write("WS_T_NAME=from-dotenv\n");

            putenv('IGNORE_DOTENV=1');
            Environment::load($this->dir);
            $this->assertSame('from-dotenv', env('WS_T_NAME'), 'only exactly "true" switches .env off');

            $this->reset();
            putenv('IGNORE_DOTENV=true');
            Environment::load($this->dir);
            $this->assertNull(env('WS_T_NAME'), '.env');

            Environment::cache($this->dir);
            $this->reset();
            Environment::load($this->dir);
            $this->assertNull(env('WS_T_NAME'), 'env cache');
        } finally {
            putenv('IGNORE_DOTENV');
        }
    }

    public function testEnvConversions(): void
    {
        $values = ['true' => true, '(true)' => true, 'FALSE' => false, 'null' => null, 'empty' => '', 'x' => 'x'];

        foreach ($values as $raw => $expected) {
            $_ENV['WS_T_BOOL'] = $raw;
            $this->assertSame($expected, env('WS_T_BOOL', 'default'), "env() for '{$raw}'");
        }

        $this->assertSame('default', env('WS_T_NOT_SET_ANYWHERE', 'default'));
    }

    public function testStoragePath(): void
    {
        $saved = $_ENV['STORAGE_PATH'] ?? null;

        try {
            unset($_ENV['STORAGE_PATH']);
            $this->assertSame(BASE_PATH . '/storage', storage_path());
            $this->assertSame(BASE_PATH . '/storage/logs', storage_path('logs'));

            foreach (['/srv/app-data/' => '/srv/app-data', 'C:\\data\\app\\' => 'C:\\data\\app', 'D:/app' => 'D:/app', '\\\\server\\share' => '\\\\server\\share'] as $set => $base) {
                $_ENV['STORAGE_PATH'] = $set;
                $this->assertSame($base, storage_path(), $set);
                $this->assertSame($base . '/cache/data', storage_path('/cache/data'), $set);
            }

            foreach (['storage', './storage', '../outside', 'C:relative', 'true'] as $relative) {
                $_ENV['STORAGE_PATH'] = $relative;
                try {
                    storage_path('logs');
                    $this->fail("{$relative} must be refused");
                } catch (RuntimeException $e) {
                    $this->assertSame('STORAGE_PATH must be an absolute path', $e->getMessage());
                }
            }
        } finally {
            if ($saved === null) {
                unset($_ENV['STORAGE_PATH']);
            } else {
                $_ENV['STORAGE_PATH'] = $saved;
            }
        }
    }
}
