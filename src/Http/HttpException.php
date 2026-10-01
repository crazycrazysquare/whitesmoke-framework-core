<?php
declare(strict_types=1);

namespace Whitesmoke\Http;

abstract class HttpException extends \RuntimeException
{
    abstract public function status(): int;
}
