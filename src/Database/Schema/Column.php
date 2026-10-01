<?php
declare(strict_types=1);

namespace Whitesmoke\Database\Schema;

use InvalidArgumentException;

final class Column
{
    public bool $nullable = false;
    public bool $unique = false;
    public bool $useCurrent = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public ?array $foreign = null;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $params = [],
    ) {}

    public function nullable(): self
    {
        $this->nullable = true;
        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;
        return $this;
    }

    public function default(string|int|float|bool|null $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;
        return $this;
    }

    /** Default to the current date and time when inserted. */
    public function useCurrent(): self
    {
        $this->useCurrent = true;
        return $this;
    }

    /** Foreign key to another table. $onDelete: restrict, cascade, set null, no action. */
    public function constrained(string $table, string $column = 'id', string $onDelete = 'restrict'): self
    {
        $onDelete = strtolower($onDelete);

        if (!in_array($onDelete, ['restrict', 'cascade', 'set null', 'no action'], true)) {
            throw new InvalidArgumentException("Invalid onDelete action: {$onDelete}");
        }

        $this->foreign = ['table' => $table, 'column' => $column, 'onDelete' => $onDelete];
        return $this;
    }
}
