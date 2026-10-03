<?php
declare(strict_types=1);

// Front controller of the small app that AppTestCaseTest drives over HTTP.
require __DIR__ . '/../../../../vendor/autoload.php';

(new Whitesmoke\Foundation\Application(dirname(__DIR__)))->run();
