<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Fixtures;

use Whitesmoke\Http\Request;
use Whitesmoke\Http\Response;

final class PassMiddleware
{
    public static int $calls = 0;

    public function handle(Request $request): ?Response
    {
        self::$calls++;
        return null;
    }
}
