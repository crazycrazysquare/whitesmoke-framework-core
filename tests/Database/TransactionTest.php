<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use LogicException;
use PDO;
use RuntimeException;
use Whitesmoke\Database\Schema\Blueprint;

final class TransactionTest extends DatabaseTestCase
{
    protected array $tables = ['tx_items'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->create('tx_items', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 50);
        });
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        parent::tearDown();
    }

    public function testCommitsAndReturnsTheResult(): void
    {
        $id = transaction(function (PDO $pdo): int|string {
            $this->assertTrue($pdo->inTransaction());
            table('tx_items')->insert(['name' => 'first']);
            return table('tx_items')->insert(['name' => 'second']);
        });

        $this->assertFalse(db()->inTransaction());
        $this->assertSame(2, table('tx_items')->count());
        $this->assertSame('second', table('tx_items')->where('id', '=', $id)->first()['name']);
    }

    public function testAnExceptionRollsBackEverythingAndIsRethrown(): void
    {
        table('tx_items')->insert(['name' => 'before']);

        try {
            transaction(function (): void {
                table('tx_items')->insert(['name' => 'inside']);
                table('tx_items')->where('name', '=', 'before')->delete();
                throw new RuntimeException('payment failed');
            });
            $this->fail('The exception must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('payment failed', $e->getMessage());
        }

        $this->assertFalse(db()->inTransaction());
        $this->assertSame(['before'], array_column(table('tx_items')->get(), 'name'));
    }

    public function testADatabaseErrorRollsBack(): void
    {
        try {
            transaction(function (): void {
                table('tx_items')->insert(['name' => 'inside']);
                table('tx_items')->insert(['missing_column' => 'x']);
            });
            $this->fail('The database error must reach the caller');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse(db()->inTransaction());
        $this->assertSame(0, table('tx_items')->count());
    }

    public function testNestedTransactionsAreRefused(): void
    {
        try {
            transaction(function (): void {
                table('tx_items')->insert(['name' => 'outer']);
                transaction(fn () => table('tx_items')->insert(['name' => 'inner']));
            });
            $this->fail('A nested transaction must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('already open', $e->getMessage());
        }

        $this->assertFalse(db()->inTransaction());
        $this->assertSame(0, table('tx_items')->count(), 'the outer work was rolled back too');

        db()->beginTransaction();
        try {
            transaction(fn () => null);
            $this->fail('A transaction inside one opened by hand must be refused');
        } catch (LogicException) {
            $this->assertTrue(db()->inTransaction(), "the caller's own transaction stays open");
        }
        db()->rollBack();
    }
}
