<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;
use Whitesmoke\Foundation\Environment;

final class EnvCacheCommand implements Command
{
    public function name(): string
    {
        return 'env:cache';
    }

    public function description(): string
    {
        return 'Compile .env into a cached PHP file for production';
    }

    public function usage(): string
    {
        return 'env:cache';
    }

    public function handle(Input $input, Output $output): int
    {
        $count = Environment::cache(BASE_PATH);

        $output->info("Cached {$count} variables to storage/cache/env.php");
        $output->comment('.env is no longer read. Run env:cache again after every .env change, or env:clear.');

        return 0;
    }
}
