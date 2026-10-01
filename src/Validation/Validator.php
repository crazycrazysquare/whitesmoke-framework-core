<?php
declare(strict_types=1);

namespace Whitesmoke\Validation;

use InvalidArgumentException;

final class Validator
{
    private array $errors = [];
    private array $data = [];

    public function __construct(private readonly array $input, array $rules)
    {
        foreach ($rules as $field => $set) {
            $this->check($field, is_array($set) ? $set : explode('|', $set));
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function data(): array
    {
        return $this->data;
    }

    private function check(string $field, array $rules): void
    {
        $label = ucfirst(str_replace('_', ' ', $field));
        $value = $this->input[$field] ?? null;

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '' || $value === null) {
            if (in_array('required', $rules, true)) {
                $this->errors[$field] = "{$label} is required.";
                return;
            }
            $this->data[$field] = null;
            return;
        }

        if (!is_string($value)) {
            $this->errors[$field] = "{$label} is invalid.";
            return;
        }

        foreach ($rules as $rule) {
            [$name, $param] = explode(':', $rule, 2) + [1 => ''];

            $error = match ($name) {
                'required', 'nullable', 'string' => null,

                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                    ? "{$label} must be a valid email address." : null,

                'int' => $this->toInt($value) === null
                    ? "{$label} must be a whole number." : null,

                'min' => $this->size($value) < (int) $param
                    ? (is_int($value) ? "{$label} must be at least {$param}." : "{$label} must be at least {$param} characters.") : null,

                'max' => $this->size($value) > (int) $param
                    ? (is_int($value) ? "{$label} must be at most {$param}." : "{$label} must be {$param} characters or less.") : null,

                'regex' => preg_match($param, $value) !== 1
                    ? "{$label} format is invalid." : null,

                'in' => !in_array($value, explode(',', $param), true)
                    ? "{$label} must be one of: " . str_replace(',', ', ', $param) . '.' : null,

                'same' => $value !== trim((string) ($this->input[$param] ?? ''))
                    ? "{$label} does not match." : null,

                'unique' => $this->exists($param, $value)
                    ? "{$label} is already taken." : null,

                default => throw new InvalidArgumentException("Unknown validation rule: {$name}"),
            };

            if ($error !== null) {
                $this->errors[$field] = $error;
                return;
            }

            if ($name === 'int') {
                $value = $this->toInt($value);
            }
        }

        $this->data[$field] = $value;
    }

    private function size(string|int $value): int
    {
        return is_int($value) ? $value : mb_strlen($value);
    }

    private function toInt(string|int $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);
        return $int === false ? null : $int;
    }

    private function exists(string $param, string|int $value): bool
    {
        [$table, $column] = explode(',', $param, 2) + [1 => ''];

        if ($table === '' || $column === '') {
            throw new InvalidArgumentException('unique rule needs table,column');
        }

        return table($table)->where($column, '=', $value)->count() > 0;
    }
}
