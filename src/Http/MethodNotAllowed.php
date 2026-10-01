<?php
declare(strict_types=1);

namespace Whitesmoke\Http;

final class MethodNotAllowed extends HttpException
{
    public function __construct(public readonly array $allowed)
    {
        parent::__construct('Method not allowed');
    }

    public function status(): int
    {
        return 405;
    }
}
