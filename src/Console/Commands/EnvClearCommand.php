<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;
use Whitesmoke\Foundation\Environment;

final class EnvClearCommand implements Command
{
    public function name(): string
    {
        return 'env:clear';
    }

    public function description(): string
    {
        return 'Remove the cached environment so .env is read again';
    }

    public function usage(): string
    {
        return 'env:clear';
    }

    public function handle(Input $input, Output $output): int
    {
        $output->info(Environment::clear(BASE_PATH) ? 'Environment cache cleared.' : 'No environment cache to clear.');

        return 0;
    }
}
