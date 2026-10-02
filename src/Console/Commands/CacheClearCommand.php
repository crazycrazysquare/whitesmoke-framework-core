<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class CacheClearCommand implements Command
{
    public function name(): string
    {
        return 'cache:clear';
    }

    public function description(): string
    {
        return 'Remove every entry from the cache';
    }

    public function usage(): string
    {
        return 'cache:clear';
    }

    public function handle(Input $input, Output $output): int
    {
        $count = cache()->clear();
        $output->info("Cache cleared ({$count} " . ($count === 1 ? 'entry' : 'entries') . ').');

        return 0;
    }
}
