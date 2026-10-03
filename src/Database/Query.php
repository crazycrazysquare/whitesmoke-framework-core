<?php
declare(strict_types=1);

namespace Whitesmoke\Database;

use Closure;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOStatement;
use UnexpectedValueException;

final class Query
{
    private const OPERATORS      = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];
    private const JOIN_OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];
    private const NAME           = '[A-Za-z_][A-Za-z0-9_]*';

    private string $driver;
    /** @var list<string> quoted select expressions */
    private array $columns = ['*'];
    /** @var list<string> quoted JOIN clauses */
    private array $joins = [];
    /** @var list<string> conditions, combined with $joiner */
    private array $wheres = [];
    /** AND or OR once two conditions are combined; the two never mix at one level. */
    private ?string $joiner = null;
    private array $bindings = [];
    private array $orders = [];
    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(private readonly PDO $pdo, private readonly string $table)
    {
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->quote($table);
    }

    /** Columns to fetch: 'name', 'users.name', 'users.*' or 'users.name AS author'. */
    public function select(string ...$columns): self
    {
        $this->columns = $columns === [] ? ['*'] : array_map($this->selectColumn(...), $columns);
        return $this;
    }

    /**
     * INNER JOIN: only rows with a match in $table. $first and $second are columns,
     * never values: put values in where(), where they are bound.
     * $table can have an alias: 'users AS author'.
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('INNER JOIN', $table, $first, $operator, $second);
    }

    /** LEFT JOIN: every row, with NULL columns where $table has no match. */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('LEFT JOIN', $table, $first, $operator, $second);
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        return $this->addWhere('AND', $this->condition($column, $operator, $value));
    }

    /**
     * OR: where(a)->orWhere(b) matches a OR b. A level is either all AND or all OR;
     * mixing them throws, so put the OR part in whereGroup().
     */
    public function orWhere(string $column, string $operator, mixed $value): self
    {
        return $this->addWhere('OR', $this->condition($column, $operator, $value));
    }

    public function whereIn(string $column, array $values): self
    {
        return $this->addWhere('AND', $this->inCondition($column, $values));
    }

    public function orWhereIn(string $column, array $values): self
    {
        return $this->addWhere('OR', $this->inCondition($column, $values));
    }

    /**
     * Conditions in parentheses: whereGroup(fn (Query $q) => $q->where(...)->orWhere(...)).
     * Only where methods belong inside.
     */
    public function whereGroup(Closure $group): self
    {
        return $this->addWhere('AND', $this->group($group));
    }

    public function orWhereGroup(Closure $group): self
    {
        return $this->addWhere('OR', $this->group($group));
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

    /**
     * One page of rows plus the total. $page is usually $request->getInt('page'):
     * null or below 1 gives page 1, past the end gives the last page. Needs orderBy(),
     * because without a fixed order rows can repeat or go missing between pages.
     */
    public function paginate(int $perPage, ?int $page = null): Page
    {
        if ($perPage < 1 || $perPage > 1000) {
            throw new InvalidArgumentException('Rows per page must be between 1 and 1000');
        }
        if ($this->orders === []) {
            throw new LogicException('paginate() needs orderBy(), so pages keep a fixed order');
        }
        if ($this->limit !== null || $this->offset !== null) {
            throw new LogicException('paginate() sets limit and offset itself');
        }

        $total = $this->count();
        $page  = min(max(1, $page ?? 1), max(1, (int) ceil($total / $perPage)));
        $items = $total === 0 ? [] : (clone $this)->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return new Page($items, $total, $page, $perPage);
    }

    public function count(): int
    {
        $sql = 'SELECT COUNT(*)' . $this->fromSql() . $this->whereSql();

        return (int) $this->run($sql, $this->bindings)->fetchColumn();
    }

    /** Total of $column over the matching rows; 0 when none match. An int for whole numbers. */
    public function sum(string $column): int|float
    {
        $value = $this->aggregate('SUM', $column);

        return match (true) {
            $value === null                                                  => 0,
            is_int($value), is_float($value)                                 => $value,
            is_string($value) && preg_match('~^-?\d{1,18}\z~', $value) === 1 => (int) $value,
            is_numeric($value)                                               => (float) $value,
            default => throw new UnexpectedValueException('SUM returned a non-numeric value'),
        };
    }

    /** Average of $column over the matching rows (never rounded to a whole number); null when none match. */
    public function avg(string $column): ?float
    {
        $value = $this->aggregate('AVG', $column);

        if ($value !== null && !is_numeric($value)) {
            throw new UnexpectedValueException('AVG returned a non-numeric value');
        }

        return $value === null ? null : (float) $value;
    }

    /** Smallest value of $column, as get() would return it; null when no row matches. */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /** Largest value of $column, as get() would return it; null when no row matches. */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
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

    private function addJoin(string $type, string $table, string $first, string $operator, string $second): self
    {
        if (!in_array($operator, self::JOIN_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid join operator: {$operator}");
        }

        $this->joins[] = " {$type} " . $this->tableName($table) . ' ON ' . $this->quote($first) . " {$operator} " . $this->quote($second);
        return $this;
    }

    /** @return array{0: string, 1: list<mixed>} SQL and its bindings */
    private function condition(string $column, string $operator, mixed $value): array
    {
        $operator = strtoupper($operator);

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}");
        }

        if ($value === null) {
            return [$this->quote($column) . match ($operator) {
                '='        => ' IS NULL',
                '!=', '<>' => ' IS NOT NULL',
                default    => throw new InvalidArgumentException('NULL only works with =, != or <>'),
            }, []];
        }

        return [$this->quote($column) . " {$operator} ?", [$value]];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function inCondition(string $column, array $values): array
    {
        $column = $this->quote($column);

        if ($values === []) {
            return ['1 = 0', []];
        }

        return [$column . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')', array_values($values)];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function group(Closure $build): array
    {
        $group = new self($this->pdo, $this->table);
        $build($group);

        if ($group->wheres === []) {
            throw new LogicException('whereGroup() needs at least one condition');
        }
        if ($group->columns !== ['*'] || $group->joins !== [] || $group->orders !== [] || $group->limit !== null || $group->offset !== null) {
            throw new LogicException('Only where conditions belong in whereGroup()');
        }

        return ['(' . implode(' ' . ($group->joiner ?? 'AND') . ' ', $group->wheres) . ')', $group->bindings];
    }

    /** @param array{0: string, 1: list<mixed>} $condition */
    private function addWhere(string $joiner, array $condition): self
    {
        if ($this->wheres !== []) {
            if ($this->joiner !== null && $this->joiner !== $joiner) {
                throw new LogicException('Mixing where() and orWhere() at one level is ambiguous: put the OR conditions in whereGroup()');
            }
            $this->joiner = $joiner;
        }

        $this->wheres[] = $condition[0];
        array_push($this->bindings, ...$condition[1]);
        return $this;
    }

    private function aggregate(string $function, string $column): mixed
    {
        if ($this->limit !== null || $this->offset !== null) {
            throw new LogicException(strtolower($function) . '() covers every matching row; remove limit() and offset()');
        }

        $column = $this->quote($column);

        // SQL Server averages whole-number columns as whole numbers (AVG of 1 and 2 is 1).
        $expression = $function === 'AVG' && $this->driver === 'sqlsrv'
            ? "AVG(CAST({$column} AS FLOAT))"
            : "{$function}({$column})";

        $value = $this->run("SELECT {$expression}" . $this->fromSql() . $this->whereSql(), $this->bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    private function selectSql(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->columns) . $this->fromSql() . $this->whereSql();

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

    private function fromSql(): string
    {
        return ' FROM ' . $this->quote($this->table) . implode('', $this->joins);
    }

    private function whereSql(): string
    {
        return $this->wheres ? ' WHERE ' . implode(' ' . ($this->joiner ?? 'AND') . ' ', $this->wheres) : '';
    }

    private function orderSql(): string
    {
        return $this->orders ? ' ORDER BY ' . implode(', ', $this->orders) : '';
    }

    private function requireWhere(string $action): void
    {
        if ($this->joins !== []) {
            throw new LogicException("Refusing to {$action} with a join: select the ids first, then {$action} with whereIn()");
        }
        if ($this->wheres === []) {
            throw new LogicException("Refusing to {$action} without a WHERE clause");
        }
    }

    private function selectColumn(string $column): string
    {
        if ($column === '*') {
            return '*';
        }
        if (preg_match('~^(' . self::NAME . ')\.\*\z~', $column, $m)) {
            return $this->quote($m[1]) . '.*';
        }
        if (preg_match('~^(\S+) +AS +(' . self::NAME . ')\z~i', $column, $m)) {
            return $this->quote($m[1]) . ' AS ' . $this->quote($m[2]);
        }

        return $this->quote($column);
    }

    private function tableName(string $table): string
    {
        if (preg_match('~^(\S+) +AS +(' . self::NAME . ')\z~i', $table, $m)) {
            return $this->quote($m[1]) . ' AS ' . $this->quote($m[2]);
        }

        return $this->quote($table);
    }

    private function quote(string $identifier): string
    {
        if (!preg_match('~^' . self::NAME . '(?:\.' . self::NAME . ')?\z~', $identifier)) {
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
