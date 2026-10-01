<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Database\Schema\Schema;

/** Drops the listed tables before and after each test so tests never see each other's data. */
abstract class DatabaseTestCase extends TestCase
{
    /** @var string[] tables this test creates, children before parents */
    protected array $tables = [];

    protected function setUp(): void
    {
        $this->dropTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
    }

    protected function schema(): Schema
    {
        return new Schema(db());
    }

    protected function driver(): string
    {
        return db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function dropTables(): void
    {
        foreach ($this->tables as $table) {
            $this->schema()->dropIfExists($table);
        }
    }
}
