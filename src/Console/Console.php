<?php
declare(strict_types=1);

namespace Whitesmoke\Console;

use ErrorException;
use InvalidArgumentException;
use Throwable;
use Whitesmoke\Foundation\Application;
use Whitesmoke\Foundation\Environment;

final class Console
{
    /** @var array<string, Command> */
    private array $commands = [];
    private Output $output;

    public function __construct(string $basePath)
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', rtrim($basePath, '/\\'));
        }

        $this->output = new Output();

        $core = [
            Commands\ServeCommand::class,
            Commands\RouteListCommand::class,
            Commands\MakeControllerCommand::class,
            Commands\MakeMiddlewareCommand::class,
            Commands\MakeCommandCommand::class,
            Commands\MakeMigrationCommand::class,
            Commands\MigrateCommand::class,
            Commands\MigrateRollbackCommand::class,
            Commands\MigrateStatusCommand::class,
            Commands\EnvCacheCommand::class,
            Commands\EnvClearCommand::class,
        ];

        $file = BASE_PATH . '/config/commands.php';
        $app  = is_file($file) ? require $file : [];

        foreach ([...$core, ...$app] as $class) {
            $this->register(new $class());
        }
    }

    public function run(array $argv): int
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            Environment::load(BASE_PATH);
        } catch (Throwable $e) {
            $this->output->error($e->getMessage());
            return 1;
        }

        $name = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        if (in_array($name, ['list', '--help', '-h'], true)) {
            $this->list();
            return 0;
        }

        if (in_array($name, ['--version', '-V'], true)) {
            $this->output->line('Whitesmoke ' . Application::VERSION);
            return 0;
        }

        if ($name === 'help') {
            return $this->help($args[0] ?? null);
        }

        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            $this->output->error("Command \"{$name}\" not found.");
            $this->output->line('Run "php smoke list" to see available commands.');
            return 1;
        }

        try {
            return $command->handle(Input::parse($args), $this->output);
        } catch (Throwable $e) {
            logger()->error("Command {$name} failed: " . $e->getMessage(), ['exception' => $e]);
            $this->output->error($e->getMessage());
            if (env('APP_DEBUG', false) === true) {
                $this->output->line($e->getTraceAsString());
            }
            return 1;
        }
    }

    private function register(Command $command): void
    {
        $name = $command->name();

        if (!preg_match('~^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)?$~', $name)) {
            throw new InvalidArgumentException("Invalid command name: {$name}");
        }

        if (isset($this->commands[$name]) || in_array($name, ['list', 'help'], true)) {
            throw new InvalidArgumentException("Command name already in use: {$name}");
        }

        $this->commands[$name] = $command;
    }

    private function list(): void
    {
        $this->output->info('Whitesmoke ' . Application::VERSION);
        $this->output->line();
        $this->output->comment('Usage:');
        $this->output->line('  php smoke <command> [arguments] [--options]');
        $this->output->line();
        $this->output->comment('Commands:');

        $commands = $this->commands;
        uksort($commands, fn (string $a, string $b): int =>
            [str_contains($a, ':'), $a] <=> [str_contains($b, ':'), $b]);

        $width = max(array_map('strlen', array_keys($commands))) + 2;
        $group = null;

        $this->output->line('  ' . $this->output->paint(str_pad('help', $width), '32') . 'Show help for a command');
        $this->output->line('  ' . $this->output->paint(str_pad('list', $width), '32') . 'List all commands');

        foreach ($commands as $name => $command) {
            $prefix = str_contains($name, ':') ? strstr($name, ':', true) : null;
            if ($prefix !== null && $prefix !== $group) {
                $this->output->comment(' ' . $prefix);
            }
            $group = $prefix;
            $this->output->line('  ' . $this->output->paint(str_pad($name, $width), '32') . $command->description());
        }
    }

    private function help(?string $name): int
    {
        if ($name === null) {
            $this->list();
            return 0;
        }

        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            $this->output->error("Command \"{$name}\" not found.");
            return 1;
        }

        $this->output->info($command->description());
        $this->output->line();
        $this->output->comment('Usage:');
        $this->output->line('  php smoke ' . $command->usage());

        return 0;
    }
}
