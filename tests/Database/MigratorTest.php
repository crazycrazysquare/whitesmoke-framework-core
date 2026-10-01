<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use RuntimeException;
use Whitesmoke\Database\Migrations\Migrator;

final class MigratorTest extends DatabaseTestCase
{
    protected array $tables = ['m_notes', 'm_items', 'm_half', 'migrations'];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = WS_TEST_TMP . '/migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    private function migration(string $name, string $up, string $down): void
    {
        file_put_contents("{$this->dir}/{$name}.php", <<<PHP
<?php
declare(strict_types=1);

use Whitesmoke\\Database\\Migration;
use Whitesmoke\\Database\\Schema\\Blueprint;
use Whitesmoke\\Database\\Schema\\Schema;

return new class implements Migration
{
    public function up(Schema \$schema): void { {$up} }
    public function down(Schema \$schema): void { {$down} }
};
PHP);
    }

    private function createTable(string $name, string $table): void
    {
        $this->migration(
            $name,
            "\$schema->create('{$table}', function (Blueprint \$t): void { \$t->id(); \$t->string('name', 50); });",
            "\$schema->dropIfExists('{$table}');"
        );
    }

    private function migrator(): Migrator
    {
        return new Migrator(db(), $this->dir);
    }

    public function testRunsInOrderAsBatchesAndRollsBack(): void
    {
        $this->createTable('2026_01_02_000000_create_notes_table', 'm_notes');
        $this->createTable('2026_01_01_000000_create_items_table', 'm_items');
        file_put_contents("{$this->dir}/readme.php", '<?php // not a migration');

        $this->assertSame(
            ['2026_01_01_000000_create_items_table', '2026_01_02_000000_create_notes_table'],
            $this->migrator()->migrate(),
            'runs in file-name order and ignores files without a timestamp name'
        );
        $this->assertSame([], $this->migrator()->migrate(), 'nothing left to run');

        $this->migration(
            '2026_01_03_000000_add_qty_to_items_table',
            "\$schema->table('m_items', fn (Blueprint \$t) => \$t->integer('qty')->default(0));",
            "\$schema->raw('ALTER TABLE ' . (\$schema->driver() === 'mysql' ? '`m_items`' : '\"m_items\"') . ' DROP COLUMN qty');"
        );
        $this->migrator()->migrate();

        $status = array_column($this->migrator()->status(), 'batch', 'name');
        $this->assertSame([
            '2026_01_01_000000_create_items_table' => 1,
            '2026_01_02_000000_create_notes_table' => 1,
            '2026_01_03_000000_add_qty_to_items_table' => 2,
        ], $status);

        table('m_items')->insert(['name' => 'x', 'qty' => 3]);

        $this->assertSame(['2026_01_03_000000_add_qty_to_items_table'], $this->migrator()->rollback());
        $this->assertNull(array_column($this->migrator()->status(), 'batch', 'name')['2026_01_03_000000_add_qty_to_items_table']);
        $this->assertArrayNotHasKey('qty', table('m_items')->first(), 'column removed by down()');

        $this->assertSame(
            ['2026_01_02_000000_create_notes_table', '2026_01_01_000000_create_items_table'],
            $this->migrator()->rollback(5),
            'newest first'
        );
        $this->assertFalse($this->schema()->hasTable('m_items'));
        $this->assertFalse($this->schema()->hasTable('m_notes'));
        $this->assertSame([], $this->migrator()->rollback(), 'nothing left to roll back');
    }

    public function testFailedMigrationIsNotRecorded(): void
    {
        $this->createTable('2026_01_01_000000_create_items_table', 'm_items');
        $this->migration(
            '2026_01_02_000000_broken',
            "\$schema->create('m_half', fn (Blueprint \$t) => \$t->id()); \$schema->raw('THIS IS NOT SQL');",
            "\$schema->dropIfExists('m_half');"
        );

        try {
            $this->migrator()->migrate();
            $this->fail('Expected failure');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('2026_01_02_000000_broken failed:', $e->getMessage());
        }

        $status = array_column($this->migrator()->status(), 'batch', 'name');
        $this->assertSame(1, $status['2026_01_01_000000_create_items_table'], 'earlier migration in the batch stays');
        $this->assertNull($status['2026_01_02_000000_broken'], 'failed migration not recorded');

        if ($this->driver() === 'mysql') {
            $this->assertTrue($this->schema()->hasTable('m_half'), 'MySQL cannot undo DDL; documented behavior');
            $this->assertStringContainsString('MySQL cannot undo schema changes', $e->getMessage());
        } else {
            $this->assertFalse($this->schema()->hasTable('m_half'), 'transaction undid the partial change');
        }
    }

    public function testFileMustReturnMigration(): void
    {
        file_put_contents("{$this->dir}/2026_01_01_000000_wrong.php", '<?php return new stdClass();');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return an object implementing');
        $this->migrator()->migrate();
    }

    public function testRollbackWithMissingFileFails(): void
    {
        $this->createTable('2026_01_01_000000_create_items_table', 'm_items');
        $this->migrator()->migrate();
        unlink("{$this->dir}/2026_01_01_000000_create_items_table.php");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migration file missing');
        $this->migrator()->rollback();
    }
}
