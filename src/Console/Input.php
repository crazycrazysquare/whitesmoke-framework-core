<?php
declare(strict_types=1);

namespace Whitesmoke\Console;

final class Input
{
    private function __construct(
        private readonly array $arguments,
        private readonly array $options,
    ) {}

    public static function parse(array $args): self
    {
        $arguments = [];
        $options   = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--') && strlen($arg) > 2) {
                [$key, $value] = explode('=', substr($arg, 2), 2) + [1 => true];
                $options[$key] = $value;
            } else {
                $arguments[] = $arg;
            }
        }

        return new self($arguments, $options);
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    public function option(string $name, string|bool|null $default = null): string|bool|null
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }
}
