<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

/** Shared logic for the make:* commands. */
final class Generator
{
    public static function make(Input $input, Output $output, string $dir, string $suffix, string $stub, string $usage): ?string
    {
        $name = $input->argument(0);

        if ($name === null) {
            $output->error('Missing name.');
            $output->line("Usage: php smoke {$usage}");
            return null;
        }

        if (!preg_match('~^[A-Z][A-Za-z0-9]*$~', $name)) {
            $output->error('Name must start with a capital letter and use only letters and digits, e.g. Report.');
            return null;
        }

        if ($suffix !== '' && !str_ends_with($name, $suffix)) {
            $name .= $suffix;
        }

        $path = BASE_PATH . "/app/{$dir}/{$name}.php";

        if (is_file($path)) {
            $output->error("app/{$dir}/{$name}.php already exists. Nothing was changed.");
            return null;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, str_replace('{{name}}', $name, $stub), LOCK_EX);

        $output->info("Created app/{$dir}/{$name}.php");

        return $name;
    }
}
