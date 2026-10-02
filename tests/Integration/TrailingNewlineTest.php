<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Auth\PasswordResets;
use Whitesmoke\Auth\SessionUser;
use Whitesmoke\Console\Console;
use Whitesmoke\Console\Output;
use Whitesmoke\Database\Connection;
use Whitesmoke\Database\Page;
use Whitesmoke\Database\Schema\Blueprint;
use Whitesmoke\Database\Schema\Schema;
use Whitesmoke\Http\Request;
use Whitesmoke\Mail\SmtpTransport;
use Whitesmoke\Security\Throttle;

/**
 * Regression test: in PHP regexes "$" also matches before a final newline, so
 * "name\n" passed checks written as ~^[a-z]+$~. Every validation now anchors
 * with \z; each check below must refuse a value with a trailing newline.
 */
final class TrailingNewlineTest extends TestCase
{
    private function refuses(callable $call, string $what): void
    {
        try {
            $call();
            $this->fail("{$what} accepted a trailing newline");
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testIdentifiersAndNamesAreRefused(): void
    {
        $this->refuses(fn () => table("users\n"), 'table name');
        $this->refuses(fn () => table('users')->select("name\n"), 'column name');
        $this->refuses(fn () => table('users')->where("id\n", '=', 1), 'where column');
        $this->refuses(fn () => (new Schema(db()))->create("t\n", fn (Blueprint $t) => $t->id()), 'schema table');
        $this->refuses(fn () => view()->render("home\n"), 'view name');
        $this->refuses(fn () => new Throttle("throttle\n"), 'throttle table');
        $this->refuses(fn () => new PasswordResets(3600, "password_resets\n"), 'reset table');
        $this->refuses(fn () => new SessionUser(null, "users\n"), 'users table');
        $this->refuses(fn () => Connection::make(['driver' => 'pgsql', 'host' => 'h', 'port' => 5432, 'database' => 'd', 'username' => 'u', 'password' => 'p', 'sslmode' => 'prefer', 'schema' => "public\n"]), 'pgsql schema');
    }

    public function testMailSettingsAreRefused(): void
    {
        $this->refuses(fn () => new SmtpTransport("localhost\n", 1025, 'none'), 'SMTP host');
        $this->refuses(fn () => new SmtpTransport("::1\n", 1025, 'none'), 'SMTP IPv6 host');
        $this->refuses(fn () => new SmtpTransport('localhost', 1025, 'none', '', '', 10, "app.example\n"), 'EHLO name');
    }

    public function testRequestValuesAreRefused(): void
    {
        $this->assertNull((new PasswordResets())->check(str_repeat('a', 64) . "\n"), 'reset token');
        $this->refuses(fn () => (new Page([], 0, 1, 10))->url(2, "/reports\n"), 'pagination path');

        $saved = [$_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null];
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = "203.0.113.7:8080\n";
        try {
            $this->assertSame('10.0.0.5', Request::capture(['10.0.0.0/8'])->ip(), 'forwarded IP with port');
        } finally {
            [$_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']] = $saved;
            if ($saved[0] === null) {
                unset($_SERVER['REMOTE_ADDR']);
            }
            if ($saved[1] === null) {
                unset($_SERVER['HTTP_X_FORWARDED_FOR']);
            }
        }
    }

    public function testConsoleNamesAreRefused(): void
    {
        $out = fopen('php://memory', 'w+');
        $console = new Console(BASE_PATH, new Output($out, $out));

        $this->assertSame(1, $console->run(['smoke', 'make:controller', "Report\n"]));
        $this->assertSame(1, $console->run(['smoke', 'make:migration', "create_reports_table\n"]));
        $this->assertFileDoesNotExist(BASE_PATH . "/app/Controllers/Report\nController.php");
        $this->assertSame([], glob(BASE_PATH . '/database/migrations/*create_reports_table*') ?: []);
    }
}
