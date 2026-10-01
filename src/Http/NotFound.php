<?php
declare(strict_types=1);

namespace Whitesmoke\Http;

final class NotFound extends HttpException
{
    public function status(): int
    {
        return 404;
    }
}
