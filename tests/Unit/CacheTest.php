<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Whitesmoke\Cache\Cache;
use Whitesmoke\Cache\FileStore;
use Whitesmoke\Console\Console;
use Whitesmoke\Console\Output;

final class CacheTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = WS_TEST_TMP . '/cache-' . bin2hex(random_bytes(4)) . '/data';
    }

    private function cache(string $prefix = ''): Cache
    {
        return new Cache(new FileStore($this->dir), $prefix);
    }

    /** The one cache file there is. */
    private function file(): string
    {
        $files = glob($this->dir . '/*/*.cache') ?: [];
        $this->assertCount(1, $files);
        return $files[0];
    }

    public function testValuesComeBackWithTheirTypes(): void
    {
        $values = [
            'string' => 'Grüße "quoted" \\ / <b>',
            'int'    => 42,
            'float'  => 1.0,
            'bool'   => false,
            'null'   => null,
            'list'   => [1, 'two', [3.5, true]],
            'map'    => ['name' => 'Ana', 'roles' => ['admin'], 'empty' => []],
        ];

        foreach ($values as $key => $value) {
            $this->cache()->set($key, $value);
            $this->assertSame($value, $this->cache()->get($key), $key);
        }
    }

    public function testMissingValuesAndNull(): void
    {
        $this->assertNull($this->cache()->get('nothing'));
        $this->assertSame('fallback', $this->cache()->get('nothing', 'fallback'));
        $this->assertFalse($this->cache()->has('nothing'));

        $this->cache()->set('nothing', null);
        $this->assertTrue($this->cache()->has('nothing'), 'a stored null is not a miss');
        $this->assertNull($this->cache()->get('nothing', 'fallback'));
    }

    public function testEntriesAreJsonOnDiskNeverSerialized(): void
    {
        $this->cache()->set('user:1', ['name' => 'Ana']);

        $this->assertSame("0\n{\"v\":{\"name\":\"Ana\"}}", file_get_contents($this->file()));
        $this->assertStringNotContainsString('user:1', $this->file(), 'file names are hashes, not keys');
    }

    public function testATamperedEntryCannotCreateObjects(): void
    {
        $this->cache()->set('k', 'x');
        file_put_contents($this->file(), "0\nO:8:\"stdClass\":0:{}");

        $this->assertSame('default', $this->cache()->get('k', 'default'), 'not JSON: treated as missing');
        $this->assertSame([], glob($this->dir . '/*/*.cache') ?: [], 'and removed');
    }

    public function testExpiry(): void
    {
        $this->cache()->set('short', 'value', 60);
        $this->assertSame('value', $this->cache()->get('short'));
        [$expires] = explode("\n", (string) file_get_contents($this->file()));
        $this->assertEqualsWithDelta(time() + 60, (int) $expires, 2);

        file_put_contents($this->file(), (time() - 1) . "\n{\"v\":\"value\"}");
        $this->assertNull($this->cache()->get('short'));
        $this->assertFalse($this->cache()->has('short'));
        $this->assertSame([], glob($this->dir . '/*/*.cache') ?: [], 'expired entry removed');
    }

    public function testRememberComputesOnlyOnAMiss(): void
    {
        $calls = 0;
        $make = function () use (&$calls): array {
            $calls++;
            return ['total' => 53];
        };

        $this->assertSame(['total' => 53], $this->cache()->remember('report', 300, $make));
        $this->assertSame(['total' => 53], $this->cache()->remember('report', 300, $make));
        $this->assertSame(1, $calls);

        $this->cache()->delete('report');
        $this->cache()->remember('report', 300, $make);
        $this->assertSame(2, $calls);
    }

    public function testClearRemovesOnlyCacheEntries(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $this->cache()->set($key, $key);
        }
        $envCache = dirname($this->dir) . '/env.php';
        file_put_contents($envCache, '<?php return [];');

        $this->assertSame(3, $this->cache()->clear());
        $this->assertNull($this->cache()->get('a'));
        $this->assertFileExists($envCache, 'the env cache next to the data folder is untouched');
        $this->assertSame(0, $this->cache()->clear());
    }

    public function testPrefixesSeparateCaches(): void
    {
        $this->cache('app1')->set('k', 'one');
        $this->cache('app2')->set('k', 'two');

        $this->assertSame('one', $this->cache('app1')->get('k'));
        $this->assertSame('two', $this->cache('app2')->get('k'));
        $this->assertNull($this->cache()->get('k'));
    }

    public function testBadKeysValuesAndTimesAreRefused(): void
    {
        $bad = [
            fn () => $this->cache()->get(''),
            fn () => $this->cache()->get('has space'),
            fn () => $this->cache()->get('../../etc/passwd'),
            fn () => $this->cache()->get("key\n"),
            fn () => $this->cache()->get(str_repeat('k', 151)),
            fn () => $this->cache()->set('k', new stdClass()),
            fn () => $this->cache()->set('k', ['nested' => [fn () => 1]]),
            fn () => $this->cache()->set('k', INF),
            fn () => $this->cache()->set('k', "\xC3\x28"),
            fn () => $this->cache()->set('k', 'v', 0),
            fn () => $this->cache('bad prefix'),
        ];

        foreach ($bad as $i => $call) {
            try {
                $call();
                $this->fail("Case {$i} must be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], glob($this->dir . '/*/*.cache') ?: [], 'nothing was written');
    }

    public function testAnUnwritableDirectoryStillFailsLoudly(): void
    {
        $blocker = WS_TEST_TMP . '/cache-blocker-' . bin2hex(random_bytes(4));
        file_put_contents($blocker, 'a file where the cache directory should be');

        $this->expectException(\RuntimeException::class);
        (new Cache(new FileStore($blocker . '/data')))->set('k', 'v');
    }

    public function testHelperAndClearCommand(): void
    {
        cache()->set('from-helper', 'yes');
        $this->assertSame('yes', cache()->get('from-helper'));

        $out = fopen('php://memory', 'w+');
        $this->assertSame(0, (new Console(BASE_PATH, new Output($out, $out)))->run(['smoke', 'cache:clear']));
        rewind($out);
        $this->assertStringContainsString('Cache cleared', (string) stream_get_contents($out));
        $this->assertNull(cache()->get('from-helper'));
    }
}
