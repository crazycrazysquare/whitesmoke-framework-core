<?php
declare(strict_types=1);

return ['path' => dirname((string) env('DB_DATABASE')) . '/logs', 'level' => 'info', 'days' => 1];
