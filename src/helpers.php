<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function js(mixed $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
}

function view(): Whitesmoke\View\View
{
    static $view = null;
    return $view ??= new Whitesmoke\View\View(BASE_PATH . '/resources/views');
}

function db(?string $name = null): PDO
{
    static $config = null, $connections = [];

    $config ??= require BASE_PATH . '/config/database.php';
    $name   ??= $config['default'];

    return $connections[$name] ??= Whitesmoke\Database\Connection::make(
        $config['connections'][$name] ?? throw new InvalidArgumentException("Unknown connection: {$name}")
    );
}

function table(string $table, ?string $connection = null): Whitesmoke\Database\Query
{
    return new Whitesmoke\Database\Query(db($connection), $table);
}

function session(): Whitesmoke\Session\Session
{
    static $session = null;
    return $session ??= new Whitesmoke\Session\Session(require BASE_PATH . '/config/session.php');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(session()->token()) . '">';
}

function validate(array $input, array $rules): Whitesmoke\Validation\Validator
{
    return new Whitesmoke\Validation\Validator($input, $rules);
}

function logger(): Whitesmoke\Log\Logger
{
    static $logger = null;

    if ($logger === null) {
        $file   = BASE_PATH . '/config/logging.php';
        $config = (is_file($file) ? require $file : []) + [
            'path'  => BASE_PATH . '/storage/logs',
            'level' => 'info',
            'days'  => 14,
        ];
        $logger = new Whitesmoke\Log\Logger($config['path'], $config['level'], (int) $config['days']);
    }

    return $logger;
}
