<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class RouteListCommand implements Command
{
    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'List all registered routes';
    }

    public function usage(): string
    {
        return 'route:list';
    }

    public function handle(Input $input, Output $output): int
    {
        $routes = require BASE_PATH . '/routes/web.php';
        $rows   = [];

        foreach ($routes as $key => $route) {
            [$method, $path] = explode(' ', $key, 2) + [1 => ''];
            $class = $route[0] ?? '';
            $short = str_starts_with($class, 'App\\Controllers\\') ? substr($class, 16) : $class;

            $rows[] = [$method, $path, $short . '@' . ($route[1] ?? '?'), implode(', ', $route[2] ?? [])];
        }

        usort($rows, fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        $output->table(['Method', 'Path', 'Action', 'Middleware'], $rows);
        $output->line();
        $output->line(count($rows) . ' routes');

        return 0;
    }
}
