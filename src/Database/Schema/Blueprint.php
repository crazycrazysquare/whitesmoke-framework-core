<?php
declare(strict_types=1);

namespace Whitesmoke\Database\Schema;

final class Blueprint
{
    /** @var Column[] */
    private array $columns = [];
    private array $indexes = [];

    public function __construct(public readonly string $table) {}

    public function id(string $name = 'id'): Column
    {
        return $this->add($name, 'id');
    }

    public function string(string $name, int $length = 255): Column
    {
        return $this->add($name, 'string', ['length' => $length]);
    }

    public function text(string $name): Column
    {
        return $this->add($name, 'text');
    }

    public function integer(string $name): Column
    {
        return $this->add($name, 'integer');
    }

    public function bigInteger(string $name): Column
    {
        return $this->add($name, 'bigInteger');
    }

    public function boolean(string $name): Column
    {
        return $this->add($name, 'boolean');
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): Column
    {
        return $this->add($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function date(string $name): Column
    {
        return $this->add($name, 'date');
    }

    public function dateTime(string $name): Column
    {
        return $this->add($name, 'dateTime');
    }

    /** Column that references another table's id, e.g. foreignId('user_id')->constrained('users'). */
    public function foreignId(string $name): Column
    {
        return $this->add($name, 'foreignId');
    }

    /** created_at (set on insert) and updated_at (nullable). */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->useCurrent();
        $this->dateTime('updated_at')->nullable();
    }

    public function index(string|array $columns): void
    {
        $this->indexes[] = (array) $columns;
    }

    /** @return Column[] */
    public function columns(): array
    {
        return $this->columns;
    }

    public function indexes(): array
    {
        return $this->indexes;
    }

    private function add(string $name, string $type, array $params = []): Column
    {
        return $this->columns[] = new Column($name, $type, $params);
    }
}
