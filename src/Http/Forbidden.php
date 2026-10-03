<?php
declare(strict_types=1);

namespace Whitesmoke\Http;

/** 403: the request is not allowed, e.g. a missing or wrong CSRF token. */
final class Forbidden extends HttpException
{
    public function status(): int
    {
        return 403;
    }
}
