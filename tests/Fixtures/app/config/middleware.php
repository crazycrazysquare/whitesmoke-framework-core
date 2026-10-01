<?php
declare(strict_types=1);

return [
    'block' => Whitesmoke\Tests\Fixtures\BlockMiddleware::class,
    'pass'  => Whitesmoke\Tests\Fixtures\PassMiddleware::class,
    'csrf'  => Whitesmoke\Middleware\Csrf::class,
];
