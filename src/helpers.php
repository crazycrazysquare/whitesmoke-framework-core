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

/** The mailer from config/mail.php, created on first use. */
function mailer(): Whitesmoke\Mail\Mailer
{
    static $mailer = null;

    if ($mailer === null) {
        $file = BASE_PATH . '/config/mail.php';

        if (!is_file($file)) {
            throw new RuntimeException('config/mail.php is missing; mail is not configured');
        }

        $mailer = new Whitesmoke\Mail\Mailer(require $file);
    }

    return $mailer;
}

/**
 * Read an environment variable. Unset or blank values return $default.
 * "true", "false", "null" and "empty" (with or without parentheses)
 * become true, false, null and "".
 */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    if (!is_string($value)) {
        return $value;
    }

    return match (strtolower($value)) {
        'true', '(true)'   => true,
        'false', '(false)' => false,
        'null', '(null)'   => null,
        'empty', '(empty)' => '',
        default            => $value,
    };
}

/** Uploaded files in storage/uploads (see Whitesmoke\Storage\Uploads). */
function uploads(): Whitesmoke\Storage\Uploads
{
    static $uploads = null;

    return $uploads ??= new Whitesmoke\Storage\Uploads(BASE_PATH . '/storage/uploads');
}
