<?php
declare(strict_types=1);

/*
 * Router for "php smoke serve" (PHP's built-in server only).
 * Serves real files inside public/ (CSS, JS, images) and sends every other
 * request to public/index.php. Never serves PHP files, dotfiles or anything
 * outside public/.
 */

$root = realpath((string) $_SERVER['DOCUMENT_ROOT']);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($root !== false && $path !== '/' && !str_contains($path, "\0") && !preg_match('~(^|/)\.~', $path)) {
    $file = realpath($root . $path);

    if ($file !== false
        && is_file($file)
        && str_starts_with($file, $root . DIRECTORY_SEPARATOR)
        && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }
}

require $root . '/index.php';
