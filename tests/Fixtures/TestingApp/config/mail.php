<?php
declare(strict_types=1);

return [
    'driver'       => (string) env('MAIL_DRIVER', ''),
    'from_address' => 'app@example.test',
    'from_name'    => 'Testing App',
];
