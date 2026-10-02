<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Whitesmoke\Console\Console;
use Whitesmoke\Console\Input;
use Whitesmoke\Console\Output;

final class ConsoleTest extends TestCase
{
    /** @var resource */
    private $out;
    /** @var resource */
    private $err;
    private array $created = [];

    protected function setUp(): void
    {
        $this->out = fopen('php://memory', 'w+');
        $this->err = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
    }

    private function smoke(string ...$args): int
    {
        return (new Console(BASE_PATH, new Output($this->out, $this->err)))->run(['smoke', ...$args]);
    }

    private function stdout(): string
    {
        rewind($this->out);
        return (string) stream_get_contents($this->out);
    }

    private function stderr(): string
    {
        rewind($this->err);
        return (string) stream_get_contents($this->err);
    }

    public function testInputParsing(): void
    {
        $input = Input::parse(['first', '--days=7', '--force', 'second', '--name=a=b']);

        $this->assertSame('first', $input->argument(0));
        $this->assertSame('second', $input->argument(1));
        $this->assertNull($input->argument(2));
        $this->assertSame('7', $input->option('days'));
        $this->assertTrue($input->option('force'));
        $this->assertSame('a=b', $input->option('name'));
        $this->assertTrue($input->hasOption('force'));
        $this->assertFalse($input->hasOption('nope'));
        $this->assertSame('d', $input->option('nope', 'd'));
    }

    public function testListShowsCommands(): void
    {
        $this->assertSame(0, $this->smoke());
        $this->assertStringContainsString('make:controller', $this->stdout());
        $this->assertStringContainsString('migrate:rollback', $this->stdout());
    }

    public function testUnknownCommandFails(): void
    {
        $this->assertSame(1, $this->smoke('nope'));
        $this->assertStringContainsString('Command "nope" not found.', $this->stderr());
    }

    public function testRouteList(): void
    {
        $this->assertSame(0, $this->smoke('route:list'));
        $this->assertStringContainsString('POST    /item', $this->stdout());
        $this->assertStringContainsString('7 routes', $this->stdout());
    }

    public function testMakeControllerCreatesFilesOnceAndValidatesNames(): void
    {
        $controller = BASE_PATH . '/app/Controllers/MonthlyReportController.php';
        $view       = BASE_PATH . '/resources/views/monthly_report/index.php';
        array_push($this->created, $controller, $view, dirname($view), BASE_PATH . '/app/Controllers', BASE_PATH . '/app');

        $this->assertSame(0, $this->smoke('make:controller', 'MonthlyReport'));
        $this->assertFileExists($controller);
        $this->assertFileExists($view);
        $this->assertStringContainsString("view()->render('monthly_report/index'", (string) file_get_contents($controller));
        exec('php -l ' . escapeshellarg($controller) . ' 2>&1', $lint, $code);
        $this->assertSame(0, $code, 'generated controller is valid PHP');

        $this->assertSame(1, $this->smoke('make:controller', 'MonthlyReport'), 'must not overwrite');

        foreach (['../../evil', 'lowercase', 'Bad-Name', ''] as $name) {
            $this->assertSame(1, $this->smoke('make:controller', $name), "name '{$name}'");
        }
    }

    public function testRunRestoresTheErrorHandler(): void
    {
        $before = $this->currentErrorHandler();

        $this->smoke();
        $this->smoke('nope');
        $this->smoke('migrate', '--connection=does_not_exist');

        $this->assertSame($before, $this->currentErrorHandler(), 'run() must not leave its error handler behind');
    }

    private function currentErrorHandler(): mixed
    {
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        return $handler;
    }

    public function testFailingCommandReturnsOneWithMessage(): void
    {
        $this->assertSame(1, $this->smoke('migrate', '--connection=does_not_exist'));
        $this->assertStringContainsString('Unknown connection: does_not_exist', $this->stderr());
    }
}
