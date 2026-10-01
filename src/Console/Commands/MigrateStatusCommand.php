<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class MigrateStatusCommand implements Command
{
    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show which migrations have run';
    }

    public function usage(): string
    {
        return 'migrate:status [--connection=name]';
    }

    public function handle(Input $input, Output $output): int
    {
        $rows = array_map(
            fn (array $m): array => [$m['batch'] === null ? 'Pending' : 'Ran', $m['batch'] ?? '', $m['name']],
            MigrateCommand::migrator($input)->status()
        );

        if ($rows === []) {
            $output->line('No migrations found in database/migrations.');
            return 0;
        }

        $output->table(['Status', 'Batch', 'Migration'], $rows);

        return 0;
    }
}
