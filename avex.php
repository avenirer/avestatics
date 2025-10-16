<?php

require_once __DIR__ . '/vendor/autoload.php';
use Avenirer\AveStatics\Statics;
use Avenirer\AveStatics\Markdown;
use Avenirer\AveStatics\Layout;
use Avenirer\AveStatics\Component;
use Avenirer\AveStatics\PathResolver;

// Base path of the project (directory containing this script)
if (!defined('AVESTATICS_BASE_PATH')) {
    define('AVESTATICS_BASE_PATH', __DIR__);
}

// Load environment variables when available (optional dependency)
if (class_exists(\Dotenv\Dotenv::class)) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

$scriptName = basename($argv[0] ?? 'avex.php');
$command = $argv[1] ?? null;

switch ($command) {
    case 'build':
        $force = parseForceFlag(array_slice($argv, 2));
        Statics::build($force);
        echo $force ? "Build completed (forced).\n" : "Build completed.\n";
        exit(0);

    case 'watch':
        runWatchCommand();
        exit(0);

    case 'help':
    case '--help':
    case '-h':
        echo "Usage: php {$scriptName} <command> [options]\n";
        echo "Commands:\n";
        echo "  build        Generate static site into the public directory\n";
        echo "  watch        Serve public/ and rebuild when content/layouts change\n";
        echo "  help         Display this message\n";
        echo "\n";
        echo "Options for build:\n";
        echo "  -f, --force  Rebuild all layouts/components even if unchanged\n";
        exit(0);

    case null:
        echo "No command provided.\n";
        echo "Run 'php {$scriptName} help' for usage information.\n";
        exit(1);

    default:
        echo "Unknown command: {$command}\n";
        echo "Run 'php {$scriptName} help' for usage information.\n";
        exit(1);
}

function runWatchCommand(): void
{
    $host = getenv('AVESTATICS_DEV_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('AVESTATICS_DEV_PORT') ?: 8000);
    $publicDir = PathResolver::getPublicDir();

    if (!is_dir($publicDir)) {
        echo "Public directory not found. Running initial build...\n";
        Statics::build();
    }

    try {
        PathResolver::ensureDirectory(PathResolver::getCacheDir());
    } catch (\RuntimeException $e) {
        echo $e->getMessage() . "\n";
    }

    $serverProcess = startPhpServer($host, $port, $publicDir);
    if (!is_resource($serverProcess)) {
        echo "Failed to start PHP development server.\n";
        exit(1);
    }

    register_shutdown_function(static function () use ($serverProcess): void {
        if (is_resource($serverProcess)) {
            proc_terminate($serverProcess);
        }
    });

    echo "Server running at http://{$host}:{$port}\n";
    $watchRoots = array_values(array_unique([
        PathResolver::getContentDir(),
        PathResolver::getLayoutsDir(),
    ]));

    $watchList = array_map('formatPathForDisplay', $watchRoots);
    if (!empty($watchList)) {
        echo "Watching for changes in: " . implode(', ', $watchList) . "\n";
    } else {
        echo "No watchable directories found. Watching skipped.\n";
    }

    $previousState = collectWatchState($watchRoots);

    while (true) {
        usleep(500000); // 0.5 seconds

        if (!isProcessAlive($serverProcess)) {
            echo "Server process stopped. Exiting watcher.\n";
            break;
        }

        $currentState = collectWatchState($watchRoots);
        $changes = detectChanges($previousState, $currentState);

        if (!empty($changes)) {
            handleChanges($changes);
            $previousState = $currentState;
        }
    }
}

function startPhpServer(string $host, int $port, string $docRoot)
{
    $address = $host . ':' . $port;

    $command = sprintf(
        'php -S %s -t %s',
        escapeshellarg($address),
        escapeshellarg($docRoot)
    );

    $descriptorSpec = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];

    return proc_open($command, $descriptorSpec, $pipes, $docRoot);
}

function isProcessAlive($process): bool
{
    if (!is_resource($process)) {
        return false;
    }

    $status = proc_get_status($process);
    return $status['running'] ?? false;
}

function collectWatchState(array $roots): array
{
    $state = [];

    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (shouldSkipWatchedPath($path)) {
                continue;
            }

            $state[$path] = $file->getMTime();
        }
    }

    return $state;
}

function shouldSkipWatchedPath(string $path): bool
{
    $layoutsBuiltDir = PathResolver::getLayoutsBuiltDir();
    if ($layoutsBuiltDir !== '' && pathIsWithin($path, $layoutsBuiltDir)) {
        return true;
    }

    if (substr($path, -1) === '~') {
        return true;
    }

    return false;
}

function detectChanges(array $previous, array $current): array
{
    $changes = [];

    foreach ($current as $path => $mtime) {
        if (!isset($previous[$path])) {
            $changes[] = ['type' => 'created', 'path' => $path];
            continue;
        }

        if ($previous[$path] !== $mtime) {
            $changes[] = ['type' => 'modified', 'path' => $path];
        }
    }

    foreach ($previous as $path => $mtime) {
        if (!isset($current[$path])) {
            $changes[] = ['type' => 'removed', 'path' => $path];
        }
    }

    return $changes;
}

function handleChanges(array $changes): void
{
    $markdownQueue = [];
    $layoutDirs = [];
    $componentDirs = [];
    $requiresFullBuild = false;
    $contentDir = PathResolver::getContentDir();
    $layoutsViewsDir = PathResolver::getLayoutsViewsDir();
    $layoutsComponentsDir = PathResolver::getLayoutsComponentsDir();

    foreach ($changes as $change) {
        $path = $change['path'];
        $exists = file_exists($path);
        $relative = formatPathForDisplay($path);

        echo ucfirst($change['type']) . ": {$relative}\n";

        if (!$exists) {
            $requiresFullBuild = true;
            continue;
        }

        if (pathIsWithin($path, $contentDir)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($extension === 'md') {
                $markdownQueue[$path] = $path;
            } else {
                $requiresFullBuild = true;
            }
            continue;
        }

        if (pathIsWithin($path, $layoutsViewsDir)) {
            $layoutDirs[dirname($path)] = dirname($path);
            $requiresFullBuild = true;
            continue;
        }

        if (pathIsWithin($path, $layoutsComponentsDir)) {
            $componentDirs[dirname($path)] = dirname($path);
            $requiresFullBuild = true;
            continue;
        }

        $requiresFullBuild = true;
    }

    foreach ($layoutDirs as $dir) {
        Layout::buildLayout($dir);
    }

    foreach ($componentDirs as $dir) {
        if (class_exists(Component::class)) {
            Component::buildComponent($dir);
        }
    }

    if ($requiresFullBuild) {
        Statics::build();
        echo "Full rebuild completed.\n";
        return;
    }

    foreach ($markdownQueue as $markdownPath) {
        Markdown::buildHtml(new \SplFileInfo($markdownPath));
    }

    if (!empty($markdownQueue)) {
        echo "Rebuilt " . count($markdownQueue) . " markdown file(s).\n";
    }
}

function formatPathForDisplay(string $path): string
{
    $relative = makeRelativeToBase($path);
    return $relative === '' ? $path : $relative;
}

function parseForceFlag(array $arguments): bool
{
    foreach ($arguments as $arg) {
        if ($arg === '-f' || $arg === '--force') {
            return true;
        }
    }

    return false;
}

function normalizePath(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $trimmed = rtrim($normalized, '/');

    if ($trimmed === '' && $normalized !== '') {
        return '/';
    }

    return $trimmed;
}

function pathIsWithin(string $path, string $root): bool
{
    $normalizedRoot = normalizePath($root);
    if ($normalizedRoot === '') {
        return false;
    }

    $normalizedPath = normalizePath($path);
    if ($normalizedPath === $normalizedRoot) {
        return true;
    }

    return strpos($normalizedPath, $normalizedRoot . '/') === 0;
}

function makeRelativeToBase(string $path): string
{
    $normalizedBase = normalizePath(PathResolver::getBasePath());
    $normalizedPath = normalizePath($path);

    if ($normalizedBase !== '' && strpos($normalizedPath, $normalizedBase . '/') === 0) {
        return substr($normalizedPath, strlen($normalizedBase) + 1);
    }

    if ($normalizedPath === $normalizedBase) {
        return '.';
    }

    return $path;
}
