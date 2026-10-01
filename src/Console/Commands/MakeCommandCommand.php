<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class MakeCommandCommand implements Command
{
    private const STUB = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class {{name}} implements Command
{
    public function name(): string
    {
        return 'app:{{key}}';
    }

    public function description(): string
    {
        return 'Describe what this command does';
    }

    public function usage(): string
    {
        return 'app:{{key}} [argument] [--option=value]';
    }

    public function handle(Input $input, Output $output): int
    {
        $output->info('{{name}} ran.');

        return 0;
    }
}

PHP;

    public function name(): string
    {
        return 'make:command';
    }

    public function description(): string
    {
        return 'Create a new console command';
    }

    public function usage(): string
    {
        return 'make:command <Name>';
    }

    public function handle(Input $input, Output $output): int
    {
        $raw  = (string) $input->argument(0, '');
        $base = preg_replace('~Command$~', '', $raw);
        $key  = strtolower(preg_replace('~(?<!^)[A-Z]~', '-$0', (string) $base));
        $stub = str_replace('{{key}}', $key, self::STUB);

        $name = Generator::make($input, $output, 'Commands', 'Command', $stub, $this->usage());

        if ($name === null) {
            return 1;
        }

        $output->line('Register it in config/commands.php:');
        $output->comment("    App\\Commands\\{$name}::class,");
        $output->line("Then run: php smoke app:{$key}");

        return 0;
    }
}
