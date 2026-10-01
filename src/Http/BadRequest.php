<?php
declare(strict_types=1);

namespace Whitesmoke\Http;

final class BadRequest extends HttpException
{
    public function status(): int
    {
        return 400;
    }
}
