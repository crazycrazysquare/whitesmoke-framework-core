<?php
declare(strict_types=1);

namespace Whitesmoke\Foundation;

use ErrorException;
use RuntimeException;
use Throwable;
use Whitesmoke\Http\HttpException;
use Whitesmoke\Http\MethodNotAllowed;
use Whitesmoke\Http\NotFound;
use Whitesmoke\Http\Request;
use Whitesmoke\Http\Response;
use Whitesmoke\Routing\Router;

final class Application
{
    public const VERSION = '0.2.6';

    public function __construct(string $basePath)
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', rtrim($basePath, '/\\'));
        }
    }

    public function run(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        $response = $this->respond();

        session()->close();
        $response->send();

        // Under PHP-FPM, end the request here so the visitor has the full response
        // while work registered with register_shutdown_function() (like sending
        // email) still runs. Elsewhere that work runs before the request ends.
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /** Capture the request and build its response, error pages included. run() sends it. */
    public function respond(): Response
    {
        $request = null;

        try {
            Environment::load(BASE_PATH);
            $request  = Request::capture(self::trustedProxies());
            $response = $this->handle($request);
        } catch (HttpException $e) {
            $response = $this->error($e->status());
            if ($e instanceof MethodNotAllowed) {
                $response->header('Allow', implode(', ', $e->allowed));
            }
        } catch (Throwable $e) {
            logger()->error($e->getMessage(), [
                'method'    => $request?->method(),
                'path'      => $request?->path(),
                'exception' => $e,
            ]);
            $response = env('APP_DEBUG', false) === true
                ? Response::text((string) $e, 500)
                : $this->error(500);
        }

        if ($request !== null) {
            $response->https($request->isSecure());
        }

        return $response;
    }

    /** Trusted proxies from config/proxies.php: a list, or a comma-separated string from .env. */
    private static function trustedProxies(): array
    {
        $file    = BASE_PATH . '/config/proxies.php';
        $trusted = is_file($file) ? ((require $file)['trusted'] ?? []) : [];

        if (is_string($trusted)) {
            $trusted = array_filter(array_map('trim', explode(',', $trusted)), fn (string $p): bool => $p !== '');
        }

        if (!is_array($trusted)) {
            throw new RuntimeException('config/proxies.php: "trusted" must be a list or a comma-separated string');
        }

        return array_values($trusted);
    }

    public function handle(Request $request): Response
    {
        $router = new Router(require BASE_PATH . '/routes/web.php');
        $route  = $router->match($request->method(), $request->path()) ?? throw new NotFound();

        [$class, $action] = $route;

        $middleware = require BASE_PATH . '/config/middleware.php';

        foreach ($route[2] ?? [] as $name) {
            $handler  = $middleware[$name] ?? throw new RuntimeException("Unknown middleware: {$name}");
            $response = (new $handler())->handle($request);
            if ($response !== null) {
                return $response;
            }
        }

        return (new $class())->$action($request);
    }

    private function error(int $status): Response
    {
        try {
            return Response::html(
                view()->render("errors/{$status}", ['title' => (string) $status], 'layouts/app'),
                $status
            );
        } catch (Throwable $e) {
            logger()->error('Error page failed: ' . $e->getMessage(), ['exception' => $e]);
            return Response::text("Error {$status}", $status);
        }
    }
}
