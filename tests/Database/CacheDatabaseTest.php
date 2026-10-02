<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use Whitesmoke\Cache\Cache;
use Whitesmoke\Cache\DatabaseStore;
use Whitesmoke\Database\Schema\Blueprint;

final class CacheDatabaseTest extends DatabaseTestCase
{
    protected array $tables = ['c_cache'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->create('c_cache', function (Blueprint $t): void {
            $t->id();
            $t->string('cache_key', 200)->unique();
            $t->text('payload');
            $t->bigInteger('expires_at');
        });
    }

    private function cache(): Cache
    {
        return new Cache(new DatabaseStore('c_cache'));
    }

    public function testValuesComeBackWithTheirTypes(): void
    {
        $value = ['name' => 'Grüße ' . str_repeat('ü', 3000), 'n' => 42, 'f' => 1.5, 'ok' => true, 'none' => null, 'list' => [1, [2]]];

        $this->cache()->set('user:1', $value, 300);

        $this->assertSame($value, $this->cache()->get('user:1'));
        $this->assertTrue($this->cache()->has('user:1'));
        $this->assertSame('{"v":', substr((string) table('c_cache')->first()['payload'], 0, 5), 'stored as JSON');
    }

    public function testOverwriteKeepsOneRow(): void
    {
        $this->cache()->set('k', 'one');
        $this->cache()->set('k', 'two', 60);

        $this->assertSame('two', $this->cache()->get('k'));
        $this->assertSame(1, table('c_cache')->count());
    }

    public function testExpiredEntriesAreMissesAndRemoved(): void
    {
        $this->cache()->set('k', 'v', 60);
        table('c_cache')->where('cache_key', '=', 'k')->update(['expires_at' => time() - 1]);

        $this->assertSame('default', $this->cache()->get('k', 'default'));
        $this->assertSame(0, table('c_cache')->count());
    }

    public function testRememberDeleteAndClear(): void
    {
        $calls = 0;
        $make = function () use (&$calls): int {
            return ++$calls;
        };

        $this->assertSame(1, $this->cache()->remember('n', null, $make));
        $this->assertSame(1, $this->cache()->remember('n', null, $make));

        $this->assertTrue($this->cache()->delete('n'));
        $this->assertFalse($this->cache()->delete('n'));

        $this->cache()->set('a', 1);
        $this->cache()->set('b', 2);
        $this->assertSame(2, $this->cache()->clear());
        $this->assertSame(0, table('c_cache')->count());
    }
}
