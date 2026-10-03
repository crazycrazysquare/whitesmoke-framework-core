<?php
declare(strict_types=1);

use Whitesmoke\Tests\Fixtures\TestingApp\Controller;

return [
    'GET /'      => [Controller::class, 'form'],
    'POST /save' => [Controller::class, 'save', ['csrf']],
];
