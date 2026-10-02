<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whitesmoke\Database\Schema\Blueprint;
use Whitesmoke\Database\Schema\Schema;

/**
 * Regression test: on Windows, renaming over a cache file that another process has
 * open failed, so most writes threw under load. Ten processes now write and read one
 * key at the same moment; no write may throw and no read may see a partial value.
 */
final class CacheRaceTest extends TestCase
{
    private const WORKERS = 10;

    /** @return array{errors: int, bad: int} */
    private function race(string $driver, string $dir): array
    {
        $worker = dirname(__DIR__) . '/Fixtures/cache-worker.php';
        $start  = (string) (microtime(true) + 2);
        $procs  = [];

        for ($w = 0; $w < self::WORKERS; $w++) {
            $procs[] = proc_open([PHP_BINARY, $worker, $driver, $dir, $start, (string) $w], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $outputs[] = $pipes;
        }

        $sum = ['errors' => 0, 'bad' => 0];
        foreach ($procs as $i => $proc) {
            $result = json_decode((string) stream_get_contents($outputs[$i][1]), true);
            $stderr = (string) stream_get_contents($outputs[$i][2]);
            proc_close($proc);

            $this->assertIsArray($result, "worker {$i} crashed: {$stderr}");
            $sum['errors'] += $result['errors'];
            $sum['bad']    += $result['bad'];
        }

        return $sum;
    }

    public function testFileStoreUnderSimultaneousWrites(): void
    {
        $dir = WS_TEST_TMP . '/cache-race';

        $this->assertSame(['errors' => 0, 'bad' => 0], $this->race('file', $dir));
        $this->assertSame([], glob($dir . '/*/*.tmp') ?: [], 'no temporary files left');
    }

    public function testDatabaseStoreUnderSimultaneousWrites(): void
    {
        if (db()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' && env('DB_DATABASE', ':memory:') === ':memory:') {
            $this->markTestSkipped('In-memory SQLite cannot be shared between processes; set DB_DATABASE to a file.');
        }

        $schema = new Schema(db());
        $schema->dropIfExists('race_cache');
        $schema->create('race_cache', function (Blueprint $t): void {
            $t->id();
            $t->string('cache_key', 200)->unique();
            $t->text('payload');
            $t->bigInteger('expires_at');
        });

        try {
            $this->assertSame(['errors' => 0, 'bad' => 0], $this->race('database', ''));
            $this->assertSame(1, table('race_cache')->count(), 'one row for the key');
        } finally {
            $schema->dropIfExists('race_cache');
        }
    }
}
