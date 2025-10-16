<?php
namespace Avenirer\AveStatics;

class PathResolver
{
    /** @var bool */
    private static $dotenvBootstrapped = false;

    public static function getBasePath(): string
    {
        return defined('AVESTATICS_BASE_PATH') ? AVESTATICS_BASE_PATH : dirname(__DIR__);
    }

    public static function getPublicDir(): string
    {
        return self::resolvePathFromEnv('AVESTATICS_PUBLIC_DIR', 'public');
    }

    public static function getContentDir(): string
    {
        return self::resolvePathFromEnv('AVESTATICS_CONTENT_DIR', 'content');
    }

    public static function getLayoutsDir(): string
    {
        return self::resolvePathFromEnv('AVESTATICS_LAYOUTS_DIR', 'layouts');
    }

    public static function getLayoutsViewsDir(): string
    {
        return self::joinPaths(self::getLayoutsDir(), 'views');
    }

    public static function getLayoutsBuiltDir(): string
    {
        return self::joinPaths(self::getLayoutsDir(), 'built');
    }

    public static function getLayoutsBuiltViewsDir(): string
    {
        return self::joinPaths(self::getLayoutsBuiltDir(), 'views');
    }

    public static function getLayoutsComponentsDir(): string
    {
        return self::joinPaths(self::getLayoutsDir(), 'components');
    }

    public static function getLayoutsBuiltComponentsDir(): string
    {
        return self::joinPaths(self::getLayoutsBuiltDir(), 'components');
    }

    public static function getCacheDir(): string
    {
        return self::resolvePathFromEnv('AVESTATICS_CACHE_DIR', 'storage/cache');
    }

    public static function getBaseUrl(): string
    {
        self::ensureDotenvLoaded();

        $value = getenv('AVESTATICS_BASE_URL');
        if ($value === false) {
            return '';
        }

        $trimmed = trim($value);
        return rtrim($trimmed, '/');
    }

    public static function joinPaths(string ...$segments): string
    {
        $segments = array_filter($segments, static function ($segment) {
            return $segment !== '';
        });

        if (empty($segments)) {
            return '';
        }

        $first = array_shift($segments);

        $path = $first === DIRECTORY_SEPARATOR ? DIRECTORY_SEPARATOR : rtrim($first, "/\\");

        foreach ($segments as $segment) {
            $segment = trim($segment, "/\\");
            if ($segment === '') {
                continue;
            }
            if ($path === '' || $path === DIRECTORY_SEPARATOR) {
                $path .= $segment;
            } else {
                $path .= DIRECTORY_SEPARATOR . $segment;
            }
        }

        return $path;
    }

    public static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create directory: {$directory}");
        }
    }

    private static function resolvePathFromEnv(string $envKey, string $default): string
    {
        self::ensureDotenvLoaded();

        $value = getenv($envKey);
        if ($value === false || trim($value) === '') {
            $value = $default;
        }

        $value = trim($value);
        if ($value === '') {
            return self::getBasePath();
        }

        $value = rtrim($value, "/\\");

        if (self::isAbsolutePath($value)) {
            return $value;
        }

        return self::joinPaths(self::getBasePath(), $value);
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return true;
        }

        return false;
    }

    private static function ensureDotenvLoaded(): void
    {
        if (self::$dotenvBootstrapped) {
            return;
        }

        self::$dotenvBootstrapped = true;

        if (!class_exists(\Dotenv\Dotenv::class)) {
            return;
        }

        $dotenv = \Dotenv\Dotenv::createImmutable(self::getBasePath());
        $dotenv->safeLoad();
    }
}
