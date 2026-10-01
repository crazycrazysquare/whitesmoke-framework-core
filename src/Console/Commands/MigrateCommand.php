<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;
use Whitesmoke\Database\Migrations\Migrator;

final class MigrateCommand implements Command
{
    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Run pending migrations';
    }

    public function usage(): string
    {
        return 'migrate [--connection=name]';
    }

    public function handle(Input $input, Output $output): int
    {
        $migrator = self::migrator($input);
        $done     = $migrator->migrate(fn (string $line) => $output->line($line));

        $output->info($done === [] ? 'Nothing to migrate.' : count($done) . ' migration(s) ran.');

        return 0;
    }

    public static function migrator(Input $input): Migrator
    {
        $connection = $input->option('connection');

        return new Migrator(
            db(is_string($connection) ? $connection : null),
            BASE_PATH . '/database/migrations'
        );
    }
}
