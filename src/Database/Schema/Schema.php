<?php
declare(strict_types=1);

namespace Whitesmoke\Database\Schema;

use Closure;
use InvalidArgumentException;
use LogicException;
use PDO;

final class Schema
{
    private string $driver;

    public function __construct(private readonly PDO $pdo)
    {
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function create(string $table, Closure $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);

        $parts = [];
        foreach ($blueprint->columns() as $column) {
            $parts[] = $this->columnSql($column);
        }
        foreach ($blueprint->columns() as $column) {
            if ($column->foreign !== null) {
                $parts[] = $this->foreignSql($column);
            }
        }

        if ($parts === []) {
            throw new LogicException("Table {$table} has no columns");
        }

        $this->pdo->exec('CREATE TABLE ' . $this->quote($table) . ' (' . implode(', ', $parts) . ')');
        $this->createIndexes($table, $blueprint);
    }

    /** Add columns and indexes to an existing table. */
    public function table(string $table, Closure $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);

        $add = $this->driver === 'sqlsrv' ? 'ADD' : 'ADD COLUMN';

        foreach ($blueprint->columns() as $column) {
            if ($column->type === 'id') {
                throw new LogicException('Cannot add an id column to an existing table');
            }
            if ($column->foreign !== null && $this->driver === 'sqlite') {
                throw new LogicException('SQLite cannot add a foreign key to an existing table');
            }

            $this->pdo->exec('ALTER TABLE ' . $this->quote($table) . " {$add} " . $this->columnSql($column));

            if ($column->foreign !== null) {
                $this->pdo->exec('ALTER TABLE ' . $this->quote($table) . ' ADD CONSTRAINT '
                    . $this->quote($this->name('fk', $table, [$column->name])) . ' ' . $this->foreignSql($column));
            }
        }

        $this->createIndexes($table, $blueprint);
    }

    public function drop(string $table): void
    {
        $this->pdo->exec('DROP TABLE ' . $this->quote($table));
    }

    public function dropIfExists(string $table): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($table));
    }

    public function hasTable(string $table): bool
    {
        $this->quote($table);

        $sql = match ($this->driver) {
            'sqlite' => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
            'mysql'  => 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            'pgsql'  => 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
            'sqlsrv' => 'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?',
        };

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    /** Run raw SQL for anything the builder does not cover. */
    public function raw(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    private function columnSql(Column $c): string
    {
        $d = $this->driver;

        if ($c->type === 'id') {
            return $this->quote($c->name) . ' ' . match ($d) {
                'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'mysql'  => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'pgsql'  => 'BIGSERIAL PRIMARY KEY',
                'sqlsrv' => 'BIGINT IDENTITY(1,1) PRIMARY KEY',
            };
        }

        $type = match ($c->type) {
            'string'     => ($d === 'sqlsrv' ? 'NVARCHAR' : 'VARCHAR') . '(' . $this->positive($c->params['length']) . ')',
            'text'       => $d === 'sqlsrv' ? 'NVARCHAR(MAX)' : 'TEXT',
            'integer'    => $d === 'mysql' ? 'INT' : 'INTEGER',
            'bigInteger' => 'BIGINT',
            'foreignId'  => match ($d) { 'mysql' => 'BIGINT UNSIGNED', 'sqlite' => 'INTEGER', default => 'BIGINT' },
            'boolean'    => match ($d) { 'mysql' => 'TINYINT(1)', 'pgsql' => 'BOOLEAN', 'sqlsrv' => 'BIT', default => 'INTEGER' },
            'decimal'    => 'DECIMAL(' . $this->positive($c->params['precision']) . ',' . (int) $c->params['scale'] . ')',
            'date'       => 'DATE',
            'dateTime'   => match ($d) { 'mysql' => 'DATETIME', 'pgsql' => 'TIMESTAMP(0)', 'sqlsrv' => 'DATETIME2(0)', default => 'TIMESTAMP' },
            default      => throw new InvalidArgumentException("Unknown column type: {$c->type}"),
        };

        $sql = $this->quote($c->name) . ' ' . $type . ($c->nullable ? ' NULL' : ' NOT NULL');

        if ($c->useCurrent) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($c->hasDefault) {
            $sql .= ' DEFAULT ' . $this->literal($c->default, $c->type);
        }

        if ($c->unique) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    private function foreignSql(Column $c): string
    {
        $action = strtoupper($c->foreign['onDelete']);

        if ($action === 'RESTRICT' && $this->driver === 'sqlsrv') {
            $action = 'NO ACTION';
        }

        return 'FOREIGN KEY (' . $this->quote($c->name) . ') REFERENCES '
            . $this->quote($c->foreign['table']) . ' (' . $this->quote($c->foreign['column']) . ')'
            . ' ON DELETE ' . $action;
    }

    private function createIndexes(string $table, Blueprint $blueprint): void
    {
        foreach ($blueprint->indexes() as $columns) {
            $this->pdo->exec('CREATE INDEX ' . $this->quote($this->name('idx', $table, $columns))
                . ' ON ' . $this->quote($table)
                . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ')');
        }
    }

    private function literal(mixed $value, string $type): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($type === 'boolean' || is_bool($value)) {
            $bool = (bool) $value;
            return $this->driver === 'pgsql' ? ($bool ? 'TRUE' : 'FALSE') : ($bool ? '1' : '0');
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $this->pdo->quote((string) $value);
    }

    private function name(string $prefix, string $table, array $columns): string
    {
        $name = $prefix . '_' . $table . '_' . implode('_', $columns);

        return strlen($name) > 60 ? substr($name, 0, 51) . '_' . substr(md5($name), 0, 8) : $name;
    }

    private function positive(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Length and precision must be positive integers');
        }
        return $value;
    }

    private function quote(string $identifier): string
    {
        if (!preg_match('~^[A-Za-z_][A-Za-z0-9_]*$~', $identifier)) {
            throw new InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        return match ($this->driver) {
            'mysql'  => "`{$identifier}`",
            'sqlsrv' => "[{$identifier}]",
            default  => "\"{$identifier}\"",
        };
    }
}
