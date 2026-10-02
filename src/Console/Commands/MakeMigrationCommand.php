<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class MakeMigrationCommand implements Command
{
    public function name(): string
    {
        return 'make:migration';
    }

    public function description(): string
    {
        return 'Create a new migration file';
    }

    public function usage(): string
    {
        return 'make:migration <name>   e.g. create_reports_table, add_phone_to_users_table';
    }

    public function handle(Input $input, Output $output): int
    {
        $name = $input->argument(0);

        if ($name === null || !preg_match('~^[a-z][a-z0-9_]*\z~', $name)) {
            $output->error('Name must be snake_case, e.g. create_reports_table.');
            return 1;
        }

        $dir = BASE_PATH . '/database/migrations';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (glob($dir . '/*_' . $name . '.php')) {
            $output->error("A migration named {$name} already exists.");
            return 1;
        }

        if (preg_match('~^create_([a-z0-9_]+)_table\z~', $name, $m)) {
            $up   = "        \$schema->create('{$m[1]}', function (Blueprint \$table): void {\n            \$table->id();\n            \$table->timestamps();\n        });";
            $down = "        \$schema->dropIfExists('{$m[1]}');";
        } elseif (preg_match('~_to_([a-z0-9_]+)_table\z~', $name, $m)) {
            $up   = "        \$schema->table('{$m[1]}', function (Blueprint \$table): void {\n            // \$table->string('column')->nullable();\n        });";
            $down = "        // Dropping columns differs by database; use \$schema->raw('ALTER TABLE ...') if needed.";
        } else {
            $up   = '        //';
            $down = '        //';
        }

        $stub = <<<PHP
<?php
declare(strict_types=1);

use Whitesmoke\\Database\\Migration;
use Whitesmoke\\Database\\Schema\\Blueprint;
use Whitesmoke\\Database\\Schema\\Schema;

return new class implements Migration
{
    public function up(Schema \$schema): void
    {
{$up}
    }

    public function down(Schema \$schema): void
    {
{$down}
    }
};

PHP;

        $file = date('Y_m_d_His') . '_' . $name . '.php';
        file_put_contents($dir . '/' . $file, $stub, LOCK_EX);

        $output->info("Created database/migrations/{$file}");

        return 0;
    }
}
