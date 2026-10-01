<?php
declare(strict_types=1);

namespace Whitesmoke\Middleware;

use Whitesmoke\Http\Request;
use Whitesmoke\Http\Response;

final class Csrf
{
    public function handle(Request $request): ?Response
    {
        if ($request->method() !== 'POST') {
            return null;
        }

        $token = $request->post('_token');

        if ($token === null || !hash_equals(session()->token(), $token)) {
            return Response::html(view()->render('errors/403', ['title' => 'Expired'], 'layouts/app'), 403);
        }

        return null;
    }
}
