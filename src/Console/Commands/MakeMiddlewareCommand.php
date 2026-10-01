<?php
declare(strict_types=1);

namespace Whitesmoke\Console\Commands;

use Whitesmoke\Console\Command;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class MakeMiddlewareCommand implements Command
{
    private const STUB = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Middleware;

use Whitesmoke\Http\Request;
use Whitesmoke\Http\Response;

final class {{name}}
{
    /** Return null to continue, or a Response to stop the request. */
    public function handle(Request $request): ?Response
    {
        return null;
    }
}

PHP;

    public function name(): string
    {
        return 'make:middleware';
    }

    public function description(): string
    {
        return 'Create a new middleware';
    }

    public function usage(): string
    {
        return 'make:middleware <Name>';
    }

    public function handle(Input $input, Output $output): int
    {
        $name = Generator::make($input, $output, 'Middleware', '', self::STUB, $this->usage());

        if ($name === null) {
            return 1;
        }

        $key = strtolower(preg_replace('~(?<!^)[A-Z]~', '-$0', $name));

        $output->line('Register it in config/middleware.php, for example:');
        $output->comment("    '{$key}' => App\\Middleware\\{$name}::class,");

        return 0;
    }
}
