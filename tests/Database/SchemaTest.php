<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use InvalidArgumentException;
use LogicException;
use PDOException;
use Whitesmoke\Database\Schema\Blueprint;

final class SchemaTest extends DatabaseTestCase
{
    protected array $tables = ['s_child', 's_parent', 's_types'];

    private function createParentAndChild(): void
    {
        $this->schema()->create('s_parent', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 10)->unique();
        });
        $this->schema()->create('s_child', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('parent_id')->constrained('s_parent');
            $t->string('label', 20);
            $t->index(['label']);
        });
    }

    public function testAllColumnTypesAndDefaults(): void
    {
        $this->schema()->create('s_types', function (Blueprint $t): void {
            $t->id();
            $t->string('title', 150);
            $t->text('body')->nullable();
            $t->string('status', 20)->default('open');
            $t->integer('views')->default(0);
            $t->bigInteger('big')->default(9000000000);
            $t->boolean('pinned')->default(false);
            $t->decimal('amount', 12, 2)->default(0);
            $t->date('due_on')->nullable();
            $t->dateTime('seen_at')->nullable();
            $t->timestamps();
        });

        $this->assertTrue($this->schema()->hasTable('s_types'));

        $id  = table('s_types')->insert(['title' => 'T', 'body' => str_repeat('long ', 2000), 'due_on' => '2026-10-02', 'seen_at' => '2026-10-02 09:30:00']);
        $row = table('s_types')->where('id', '=', $id)->first();

        $this->assertSame('open', $row['status']);
        $this->assertSame(0, (int) $row['views']);
        $this->assertSame(9000000000, (int) $row['big']);
        $this->assertFalse((bool) $row['pinned']);
        $this->assertSame(0.0, (float) $row['amount']);
        $this->assertSame('2026-10-02', substr((string) $row['due_on'], 0, 10));
        $this->assertSame('2026-10-02 09:30:00', substr((string) $row['seen_at'], 0, 19));
        $this->assertNotEmpty($row['created_at']);
        $this->assertNull($row['updated_at']);
        $this->assertSame(10000, strlen($row['body']));
    }

    public function testSqlServerDefaultsHavePredictableNames(): void
    {
        if ($this->schema()->driver() !== 'sqlsrv') {
            $this->markTestSkipped('SQL Server only.');
        }

        $this->schema()->create('s_types', function (Blueprint $t): void {
            $t->id();
            $t->integer('qty')->default(0);
        });
        $this->schema()->table('s_types', fn (Blueprint $t) => $t->dateTime('seen_at')->useCurrent());

        $names = db()->query("SELECT name FROM sys.default_constraints WHERE parent_object_id = OBJECT_ID('s_types') ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['df_s_types_qty', 'df_s_types_seen_at'], $names);

        foreach (['qty', 'seen_at'] as $column) {
            $this->schema()->raw("ALTER TABLE [s_types] DROP CONSTRAINT [df_s_types_{$column}]");
            $this->schema()->raw("ALTER TABLE [s_types] DROP COLUMN [{$column}]");
        }

        $columns = db()->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 's_types'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['id'], $columns);
    }

    public function testNullableUniqueAllowsManyNullsButNoDuplicates(): void
    {
        $this->schema()->create('s_types', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 10)->nullable()->unique();
        });

        table('s_types')->insert(['code' => null]);
        table('s_types')->insert(['code' => null]);
        table('s_types')->insert(['code' => 'A1']);
        $this->assertSame(3, table('s_types')->count());

        try {
            table('s_types')->insert(['code' => 'A1']);
            $this->fail('Duplicate value must be rejected');
        } catch (PDOException $e) {
            $this->assertSame(3, table('s_types')->count());
        }

        if ($this->schema()->driver() === 'sqlsrv') {
            $index = db()->query("SELECT name FROM sys.indexes WHERE object_id = OBJECT_ID('s_types') AND is_unique = 1 AND has_filter = 1")->fetchColumn();
            $this->assertSame('uq_s_types_code', $index);
        }
    }

    public function testHasTableIgnoresTablesInOtherSchemas(): void
    {
        $driver = $this->schema()->driver();

        if ($driver !== 'sqlsrv' && $driver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL and SQL Server only.');
        }

        $create = $driver === 'sqlsrv' ? "EXEC('CREATE SCHEMA ws_other')" : 'CREATE SCHEMA ws_other';
        db()->exec($create);

        try {
            db()->exec('CREATE TABLE ws_other.s_ghost (id INT)');
            $this->assertFalse($this->schema()->hasTable('s_ghost'));
        } finally {
            db()->exec('DROP TABLE IF EXISTS ws_other.s_ghost');
            db()->exec('DROP SCHEMA ws_other');
        }
    }

    public function testDecimalKeepsExactCents(): void
    {
        $this->schema()->create('s_types', function (Blueprint $t): void {
            $t->id();
            $t->decimal('amount', 12, 2);
        });

        table('s_types')->insert(['amount' => '1234567.89']);

        $this->assertSame('1234567.89', number_format((float) table('s_types')->first()['amount'], 2, '.', ''));
    }

    public function testUniqueAndNotNullEnforced(): void
    {
        $this->createParentAndChild();
        table('s_parent')->insert(['code' => 'A']);

        $this->expectException(PDOException::class);
        table('s_parent')->insert(['code' => 'A']);
    }

    public function testNotNullEnforced(): void
    {
        $this->createParentAndChild();

        $this->expectException(PDOException::class);
        db()->exec('INSERT INTO s_parent (code) VALUES (NULL)');
    }

    public function testForeignKeyRestrictsOrphansAndParentDelete(): void
    {
        $this->createParentAndChild();
        $parent = table('s_parent')->insert(['code' => 'P']);
        table('s_child')->insert(['parent_id' => $parent, 'label' => 'ok']);

        try {
            table('s_child')->insert(['parent_id' => 999999, 'label' => 'orphan']);
            $this->fail('Orphan insert should fail');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }

        try {
            table('s_parent')->where('id', '=', $parent)->delete();
            $this->fail('Deleting a referenced parent should fail with restrict');
        } catch (PDOException) {
            $this->assertSame(1, table('s_parent')->count());
        }
    }

    public function testAlterAddsColumnsAndIndexes(): void
    {
        $this->createParentAndChild();

        $this->schema()->table('s_parent', function (Blueprint $t): void {
            $t->string('phone', 20)->nullable();
            $t->integer('rank')->default(5);
            $t->index('phone');
        });

        table('s_parent')->insert(['code' => 'B', 'phone' => '+971500000000']);
        $row = table('s_parent')->first();

        $this->assertSame('+971500000000', $row['phone']);
        $this->assertSame(5, (int) $row['rank']);
    }

    public function testDropIfExistsIsIdempotent(): void
    {
        $this->createParentAndChild();
        $this->schema()->dropIfExists('s_child');
        $this->schema()->dropIfExists('s_child');

        $this->assertFalse($this->schema()->hasTable('s_child'));
        $this->assertTrue($this->schema()->hasTable('s_parent'));
    }

    public function testRejectsUnsafeOrInvalidDefinitions(): void
    {
        $cases = [
            InvalidArgumentException::class => [
                fn () => $this->schema()->create('s_types; DROP TABLE x', fn (Blueprint $t) => $t->id()),
                fn () => $this->schema()->create('s_types', fn (Blueprint $t) => $t->string('a b')),
                fn () => $this->schema()->create('s_types', fn (Blueprint $t) => $t->string('name', 0)),
                fn () => $this->schema()->create('s_types', fn (Blueprint $t) => $t->foreignId('p')->constrained('s_parent', onDelete: 'drop table')),
            ],
            LogicException::class => [
                fn () => $this->schema()->create('s_types', function (Blueprint $t): void {}),
            ],
        ];

        foreach ($cases as $exception => $ops) {
            foreach ($ops as $i => $op) {
                try {
                    $op();
                    $this->fail("{$exception} case {$i} not thrown");
                } catch (InvalidArgumentException | LogicException $e) {
                    $this->assertInstanceOf($exception, $e);
                }
            }
        }

        $this->assertFalse($this->schema()->hasTable('s_types'));
    }

    public function testCannotAddIdToExistingTable(): void
    {
        $this->createParentAndChild();

        $this->expectException(LogicException::class);
        $this->schema()->table('s_parent', fn (Blueprint $t) => $t->id('other_id'));
    }

    public function testDefaultsAreQuotedSafely(): void
    {
        $this->schema()->create('s_types', function (Blueprint $t): void {
            $t->id();
            $t->integer('n');
            $t->string('note', 50)->default("it's; DROP TABLE s_types; --");
        });

        table('s_types')->insert(['n' => 1]);

        $this->assertSame("it's; DROP TABLE s_types; --", table('s_types')->first()['note']);
    }
}
