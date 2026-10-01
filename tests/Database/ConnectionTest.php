<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Database\Connection;

final class ConnectionTest extends TestCase
{
    private function mysql(array $override): array
    {
        return $override + ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'db', 'username' => 'u', 'password' => 'p', 'charset' => 'utf8mb4'];
    }

    public function testDsnInjectionRejectedBeforeConnecting(): void
    {
        foreach ([['host' => 'h;dbname=other'], ['database' => 'db;unix_socket=/tmp/x'], ['charset' => 'utf8;x=1'], ['host' => ''], ['database' => "db\0"]] as $bad) {
            try {
                Connection::make($this->mysql($bad));
                $this->fail('Should reject ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid DB connection value', $e->getMessage());
            }
        }
    }

    public function testInvalidPostgresSchemaRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Connection::make(['driver' => 'pgsql', 'host' => 'h', 'port' => 5432, 'database' => 'd', 'username' => 'u', 'password' => 'p', 'sslmode' => 'prefer', 'schema' => 'public"; DROP']);
    }

    public function testUnsupportedDriverRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Connection::make(['driver' => 'oracle']);
    }

    public function testUnknownConnectionNameRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        db('does_not_exist');
    }
}
