<?php
declare(strict_types=1);

namespace Whitesmoke\Routing;

use Whitesmoke\Http\MethodNotAllowed;

final class Router
{
    public function __construct(private readonly array $routes) {}

    public function match(string $method, string $path): ?array
    {
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        if ($method !== 'GET' && $method !== 'POST') {
            throw new MethodNotAllowed(['GET', 'POST']);
        }

        return $this->routes[$method . ' /' . trim($path, '/')] ?? null;
    }
}
