<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class MigrateRollbackCommand implements Command
{
    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Undo the last batch of migrations';
    }

    public function usage(): string
    {
        return 'migrate:rollback [--steps=1] [--connection=name]';
    }

    public function handle(Input $input, Output $output): int
    {
        $steps = (string) $input->option('steps', '1');

        if (!ctype_digit($steps) || (int) $steps < 1) {
            $output->error('--steps must be a positive number.');
            return 1;
        }

        $done = MigrateCommand::migrator($input)->rollback((int) $steps, fn (string $line) => $output->line($line));

        $output->info($done === [] ? 'Nothing to roll back.' : count($done) . ' migration(s) rolled back.');

        return 0;
    }
}
