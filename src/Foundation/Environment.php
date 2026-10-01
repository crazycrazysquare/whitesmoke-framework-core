<?php
declare(strict_types=1);

namespace Whitesmoke\Foundation;

use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface;
use Dotenv\Repository\Adapter\EnvConstAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\Adapter\ServerConstAdapter;
use Dotenv\Repository\RepositoryBuilder;
use RuntimeException;

/**
 * Loads .env into $_ENV and $_SERVER.
 *
 * - Real environment variables (PHP-FPM pool, shell, Docker) always win over .env.
 * - With storage/cache/env.php present (php smoke env:cache), .env is not parsed at all.
 */
final class Environment
{
    private static bool $loaded = false;

    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $cache = self::cachePath($basePath);

        if (is_file($cache)) {
            foreach ((require $cache) as $key => $value) {
                if (!self::exists($key)) {
                    $_ENV[$key] = $_SERVER[$key] = $value;
                }
            }
            return;
        }

        if (!is_file($basePath . '/.env')) {
            return;
        }

        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addReader(PutenvAdapter::class)
            ->addAdapter(EnvConstAdapter::class)
            ->addAdapter(ServerConstAdapter::class)
            ->immutable()
            ->make();

        try {
            Dotenv::create($repository, $basePath)->load();
        } catch (ExceptionInterface $e) {
            throw new RuntimeException('.env is invalid: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Parse .env and write it as a PHP array that OPcache keeps in memory. Returns the number of keys. */
    public static function cache(string $basePath): int
    {
        $file = $basePath . '/.env';

        if (!is_file($file)) {
            throw new RuntimeException('No .env file to cache.');
        }

        try {
            $parsed = Dotenv::parse((string) file_get_contents($file));
        } catch (ExceptionInterface $e) {
            throw new RuntimeException('.env is invalid: ' . $e->getMessage(), 0, $e);
        }

        $values = array_map(static fn (?string $v): string => $v ?? '', $parsed);

        $cache = self::cachePath($basePath);
        $dir   = dirname($cache);

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $tmp = $cache . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "<?php\n\nreturn " . var_export($values, true) . ";\n", LOCK_EX);
        @chmod($tmp, 0640);
        rename($tmp, $cache);

        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($cache, true);
        }

        return count($values);
    }

    public static function clear(string $basePath): bool
    {
        $cache = self::cachePath($basePath);

        if (!is_file($cache)) {
            return false;
        }

        unlink($cache);

        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($cache, true);
        }

        return true;
    }

    private static function cachePath(string $basePath): string
    {
        return $basePath . '/storage/cache/env.php';
    }

    private static function exists(string $key): bool
    {
        return isset($_ENV[$key]) || isset($_SERVER[$key]) || getenv($key) !== false;
    }
}
