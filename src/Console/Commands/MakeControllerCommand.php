<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class MakeControllerCommand implements Command
{
    private const STUB = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Controllers;

use Whitesmoke\Http\Request;
use Whitesmoke\Http\Response;

final class {{name}}
{
    public function index(Request $request): Response
    {
        return Response::html(view()->render('{{view}}/index', [
            'title' => '{{title}}',
        ], 'layouts/app'));
    }
}

PHP;

    public function name(): string
    {
        return 'make:controller';
    }

    public function description(): string
    {
        return 'Create a new controller';
    }

    public function usage(): string
    {
        return 'make:controller <Name>';
    }

    public function handle(Input $input, Output $output): int
    {
        $base  = preg_replace('~Controller$~', '', (string) $input->argument(0, ''));
        $view  = strtolower((string) preg_replace('~(?<!^)[A-Z]~', '_$0', (string) $base));
        $title = trim((string) preg_replace('~(?<!^)[A-Z]~', ' $0', (string) $base));
        $stub  = str_replace(['{{view}}', '{{title}}'], [$view, $title], self::STUB);

        $name = Generator::make($input, $output, 'Controllers', 'Controller', $stub, $this->usage());

        if ($name === null) {
            return 1;
        }

        $viewFile = BASE_PATH . "/resources/views/{$view}/index.php";

        if (!is_file($viewFile)) {
            if (!is_dir(dirname($viewFile))) {
                mkdir(dirname($viewFile), 0755, true);
            }
            file_put_contents($viewFile, "<h1><?= e(\$title) ?></h1>\n", LOCK_EX);
            $output->info("Created resources/views/{$view}/index.php");
        }

        $output->line('Add a route in routes/web.php, for example:');
        $output->comment("    'GET /path' => [App\\Controllers\\{$name}::class, 'index', ['auth']],");

        return 0;
    }
}
