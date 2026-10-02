<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use Whitesmoke\Database\Schema\Blueprint;

/**
 * Regression test for the 2026-10-01 security fix: simultaneous login guesses
 * must not get past the limit. Starts separate PHP processes that all call
 * Throttle::hit() at the same moment, as parallel HTTP requests would.
 */
final class ThrottleRaceTest extends DatabaseTestCase
{
    private const WORKERS = 20;
    private const MAX = 5;

    protected array $tables = ['throttle'];

    public function testSimultaneousAttemptsCannotPassTheLimit(): void
    {
        if ($this->schema()->driver() === 'sqlite') {
            $this->markTestSkipped('The test database is in-memory SQLite, which other processes cannot share.');
        }

        $this->schema()->create('throttle', function (Blueprint $t): void {
            $t->id();
            $t->string('throttle_key', 100)->unique();
            $t->integer('attempts')->default(0);
            $t->bigInteger('window_ends');
            $t->bigInteger('locked_until')->default(0);
        });

        $worker = dirname(__DIR__) . '/Fixtures/throttle-worker.php';
        $start  = (string) (microtime(true) + 2);
        $procs  = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $procs[] = proc_open(
                [PHP_BINARY, $worker, $start, 'race@example.test', (string) self::MAX],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $outputs[] = $pipes;
        }

        $counts = [];
        foreach ($procs as $i => $proc) {
            $counts[] = (int) stream_get_contents($outputs[$i][1]);
            $error    = (string) stream_get_contents($outputs[$i][2]);
            proc_close($proc);
            $this->assertSame('', $error, 'worker failed');
        }

        sort($counts);

        $this->assertSame(range(1, self::WORKERS), $counts, 'every attempt is counted exactly once');
        $this->assertCount(self::MAX, array_filter($counts, fn (int $c): bool => $c <= self::MAX), 'only MAX attempts pass');
        $this->assertSame(1, table('throttle')->where('throttle_key', '=', 'race@example.test')->count(), 'one row per key');
    }
}
