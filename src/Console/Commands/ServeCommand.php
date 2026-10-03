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

        $address = str_contains($host, ':') ? "[{$host}]:{$port}" : "{$host}:{$port}";

        $output->info("Whitesmoke development server: http://{$address}");
        $output->comment('Press Ctrl+C to stop. Development only, never use in production.');

        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $output->error("Warning: {$host} makes this server reachable from other devices on your network,");
            $output->error('with insecure session cookies and possibly debug output. Never use it with real data.');
        }

        // An argument list, not a shell command: paths reach PHP unchanged. (On Windows,
        // escapeshellarg() turns %, ! and " into spaces, which broke project paths
        // such as "C:\dev\100% done".) The server shares this console's input and output.
        $server = proc_open(
            [PHP_BINARY, '-S', $address, '-t', BASE_PATH . '/public', dirname(__DIR__) . '/server.php'],
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes
        );

        if (!is_resource($server)) {
            $output->error('Could not start the PHP development server.');
            return 1;
        }

        return proc_close($server);
    }
}
