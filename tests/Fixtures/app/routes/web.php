<?php
declare(strict_types=1);

use Whitesmoke\Tests\Fixtures\TestController;

return [
    'GET /'          => [TestController::class, 'index'],
    'GET /item'      => [TestController::class, 'show'],
    'POST /item'     => [TestController::class, 'store', ['pass']],
    'GET /blocked'   => [TestController::class, 'index', ['pass', 'block']],
    'GET /typo'      => [TestController::class, 'index', ['missing']],
    'GET /boom'      => [TestController::class, 'boom'],
    'POST /form'     => [TestController::class, 'index', ['csrf']],
];
