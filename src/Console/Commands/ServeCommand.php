<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class ServeCommand implements Command
{
    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'Start the development server';
    }

    public function usage(): string
    {
        return 'serve [--host=127.0.0.1] [--port=8000]';
    }

    public function handle(Input $input, Output $output): int
    {
        $host = (string) $input->option('host', '127.0.0.1');
        $port = (string) $input->option('port', '8000');

        if (!filter_var($host, FILTER_VALIDATE_IP) && $host !== 'localhost') {
            $output->error('Invalid host. Use an IP address or localhost.');
            return 1;
        }

        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $output->error('Invalid port. Use a number between 1 and 65535.');
            return 1;
        }

        putenv('SESSION_SECURE=false');

        $output->info("Whitesmoke development server: http://{$host}:{$port}");
        $output->comment('Press Ctrl+C to stop. Development only, never use in production.');

        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, '-S', "{$host}:{$port}",
            '-t', BASE_PATH . '/public',
            BASE_PATH . '/public/index.php',
        ]));

        passthru($command, $code);

        return $code;
    }
}
