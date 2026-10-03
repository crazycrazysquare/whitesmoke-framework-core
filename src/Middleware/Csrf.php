<?php
declare(strict_types=1);

namespace Whitesmoke\Middleware;

use Whitesmoke\Http\Forbidden;
use Whitesmoke\Http\Request;
use Whitesmoke\Http\Response;

final class Csrf
{
    /**
     * Refuses a POST without the session's token with a 403, rendered by Application
     * like every error page (errors/403.php, or plain text when the view is missing).
     */
    public function handle(Request $request): ?Response
    {
        if ($request->method() !== 'POST') {
            return null;
        }

        $token = $request->post('_token');

        if ($token === null || !hash_equals(session()->token(), $token)) {
            throw new Forbidden();
        }

        return null;
    }
}
