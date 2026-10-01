<?php
declare(strict_types=1);

namespace Whitesmoke\Database;

use InvalidArgumentException;
use LogicException;
use PDO;
use PDOStatement;

final class Query
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    private string $driver;
    private array $columns = ['*'];
    private array $wheres = [];
    private array $bindings = [];
    private array $orders = [];
    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(private readonly PDO $pdo, private readonly string $table)
    {
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->quote($table);
    }

    public function select(string ...$columns): self
    {
        array_map($this->quote(...), $columns);
        $this->columns = $columns ?: ['*'];
        return $this;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $operator = strtoupper($operator);

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}");
        }

        if ($value === null) {
            $this->wheres[] = $this->quote($column) . match ($operator) {
                '='        => ' IS NULL',
                '!=', '<>' => ' IS NOT NULL',
                default    => throw new InvalidArgumentException('NULL only works with =, != or <>'),
            };
            return $this;
        }

        $this->wheres[] = $this->quote($column) . " {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            $this->wheres[] = '1 = 0';
            return $this;
        }

        $marks = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = $this->quote($column) . " IN ({$marks})";
        array_push($this->bindings, ...array_values($values));
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);

        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new InvalidArgumentException('Direction must be ASC or DESC');
        }

        $this->orders[] = $this->quote($column) . ' ' . $direction;
        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be at least 1');
        }
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset cannot be negative');
        }
        $this->offset = $offset;
        return $this;
    }

    public function get(): array
    {
        return $this->run($this->selectSql(), $this->bindings)->fetchAll();
    }

    public function first(): ?array
    {
        $query = clone $this;
        $query->limit = 1;

        return $query->run($query->selectSql(), $query->bindings)->fetch() ?: null;
    }

    public function count(): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->quote($this->table) . $this->whereSql();

        return (int) $this->run($sql, $this->bindings)->fetchColumn();
    }

    public function insert(array $data, string $key = 'id'): int|string
    {
        if ($data === []) {
            throw new InvalidArgumentException('Insert data cannot be empty');
        }

        $table = $this->quote($this->table);
        $cols  = implode(', ', array_map($this->quote(...), array_keys($data)));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        $pk    = $this->quote($key);

        $sql = match ($this->driver) {
            'pgsql'  => "INSERT INTO {$table} ({$cols}) VALUES ({$marks}) RETURNING {$pk}",
            'sqlsrv' => "INSERT INTO {$table} ({$cols}) OUTPUT INSERTED.{$pk} VALUES ({$marks})",
            default  => "INSERT INTO {$table} ({$cols}) VALUES ({$marks})",
        };

        $stmt = $this->run($sql, array_values($data));

        $id = match ($this->driver) {
            'pgsql', 'sqlsrv' => $stmt->fetchColumn(),
            default           => $this->pdo->lastInsertId(),
        };

        return is_numeric($id) ? (int) $id : (string) $id;
    }

    public function update(array $data): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Update data cannot be empty');
        }

        $this->requireWhere('update');

        $sets = implode(', ', array_map(fn (string $col): string => $this->quote($col) . ' = ?', array_keys($data)));
        $sql  = 'UPDATE ' . $this->quote($this->table) . " SET {$sets}" . $this->whereSql();

        return $this->run($sql, [...array_values($data), ...$this->bindings])->rowCount();
    }

    public function delete(): int
    {
        $this->requireWhere('delete');

        $sql = 'DELETE FROM ' . $this->quote($this->table) . $this->whereSql();

        return $this->run($sql, $this->bindings)->rowCount();
    }

    private function selectSql(): string
    {
        $cols = implode(', ', array_map($this->quote(...), $this->columns));
        $sql  = "SELECT {$cols} FROM " . $this->quote($this->table) . $this->whereSql();

        if ($this->offset !== null && $this->limit === null) {
            throw new LogicException('Offset requires a limit');
        }

        if ($this->driver === 'sqlsrv') {
            if ($this->limit === null) {
                return $sql . $this->orderSql();
            }
            $order = $this->orders ? $this->orderSql() : ' ORDER BY (SELECT NULL)';
            return $sql . $order . ' OFFSET ' . ($this->offset ?? 0) . ' ROWS FETCH NEXT ' . $this->limit . ' ROWS ONLY';
        }

        $sql .= $this->orderSql();

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    private function whereSql(): string
    {
        return $this->wheres ? ' WHERE ' . implode(' AND ', $this->wheres) : '';
    }

    private function orderSql(): string
    {
        return $this->orders ? ' ORDER BY ' . implode(', ', $this->orders) : '';
    }

    private function requireWhere(string $action): void
    {
        if ($this->wheres === []) {
            throw new LogicException("Refusing to {$action} without a WHERE clause");
        }
    }

    private function quote(string $identifier): string
    {
        if ($identifier === '*') {
            return '*';
        }

        if (!preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$~', $identifier)) {
            throw new InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        return implode('.', array_map(
            fn (string $part): string => match ($this->driver) {
                'mysql'  => "`{$part}`",
                'sqlsrv' => "[{$part}]",
                default  => "\"{$part}\"",
            },
            explode('.', $identifier)
        ));
    }

    private function run(string $sql, array $bindings): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        foreach (array_values($bindings) as $i => $value) {
            $stmt->bindValue($i + 1, $value, match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            });
        }

        $stmt->execute();

        return $stmt;
    }
}
