<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Fixtures;

use Whitesmoke\Http\Request;
use Whitesmoke\Http\Response;

final class BlockMiddleware
{
    public function handle(Request $request): ?Response
    {
        return Response::text('blocked', 403);
    }
}
