<?php
namespace Avenirer\AveStatics;
use Avenirer\AveStatics\Layout;
use Avenirer\AveStatics\PathResolver;
use Parsedown;

class Markdown
{
    /** @var Parsedown|null */
    private static $parsedown = null;

    public static function buildHtml(\SplFileInfo $fileinfo): ?string
    {
        $path = $fileinfo->getPathname();
        if (!is_file($path)) {
            echo "Could not find markdown file for: {$path}\n";
            return null;
        }
        echo "Building HTML for: {$path}\n";

        $mdContent = file_get_contents($path);

        $frontMatter = self::getFrontMatter($mdContent);

        if(!isset($frontMatter['layout'])) {
            echo 'No layout was defined for ' . $path . "\n";
            return null;
        }


        $mdContent = preg_replace('/^---\s*\n(.*?)\n---\s*(?:\n|$)/s', '', $mdContent);

        $contentPath = PathResolver::getContentDir();
        $publicPath = PathResolver::getPublicDir();

        $layoutPath = Layout::ensureLayoutBuilt($frontMatter['layout']);
        if (!$layoutPath || !is_file($layoutPath)) {
            echo "Layout could not be built or found for {$path}\n";
            return null;
        }

        $layoutContent = file_get_contents($layoutPath);
        if ($layoutContent === false) {
            echo "Unable to read layout file at {$layoutPath}\n";
            return null;
        }

        if (!isset($frontMatter['base_url'])) {
            $frontMatter['base_url'] = PathResolver::getBaseUrl();
        }

        $layoutContent = self::applyParams($layoutContent, $frontMatter);

        $layoutContent = self::applyMD($layoutContent, $mdContent);

        $layoutName = pathinfo($layoutPath, PATHINFO_FILENAME);
        $layoutSourceDir = Layout::getLayoutSourceDir($layoutName);
        $layoutContent = Layout::applySS($layoutContent, $layoutSourceDir);

        try {
            $outputPath = self::resolveOutputFilePath($fileinfo, $frontMatter, $contentPath, $publicPath);
            self::ensureDirectoryExists(dirname($outputPath));
        } catch (\RuntimeException $e) {
            echo $e->getMessage() . "\n";
            return null;
        }

        $archivePages = LayoutList::build($fileinfo, $layoutContent, $frontMatter);

        if($archivePages > 0)
        {
            echo "Generated {$archivePages} archive pages to {$outputPath}";

        }
        else if (file_put_contents($outputPath, $layoutContent) === false) {
            echo "Failed to write generated HTML to {$outputPath}\n";
            return null;
        }

        return $outputPath;
    }

    private static function applyMD(string $layoutContent, string $mdContent): string {
        $parsedMD = self::getParsedown()->text($mdContent);
        return str_replace('<x-md></x-md>', $parsedMD, $layoutContent);
    }

    public static function applyParams(string $html, array $params): string
	{
		if (empty($params)) {
			return $html;
		}
		return preg_replace_callback('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_:\\-]*)\s*(?:\|\s*([^}]*))?\s*\}\}/', function($m) use ($params) {
			$key = $m[1];
            if (array_key_exists($key, $params)) {
				return (string) $params[$key];
			}

            if (array_key_exists(2, $m)) {
                return $m[2];
            }

			return $m[0];
		}, $html);
	}

    private static function getParsedown(): Parsedown
    {
        if (!self::$parsedown instanceof Parsedown) {
            self::$parsedown = (new Parsedown())->setBreaksEnabled(true);
        }

        return self::$parsedown;
    }

    private static function resolveOutputFilePath(\SplFileInfo $fileinfo, array $frontMatter, string $contentRoot, string $publicRoot): string
    {
        $defaultFileName = $fileinfo->getBasename('.md') . '.html';
        $configuredTarget = isset($frontMatter['file']) ? (string) $frontMatter['file'] : '';

        $relativeTarget = self::sanitizeRelativeTarget($configuredTarget, $defaultFileName);
        $relativeDirectory = self::normalizeRelativeDirectory($fileinfo->getPath(), $contentRoot);

        $outputDirectory = rtrim($publicRoot, DIRECTORY_SEPARATOR);
        if ($relativeDirectory !== '') {
            $outputDirectory .= DIRECTORY_SEPARATOR . $relativeDirectory;
        }

        return $outputDirectory . DIRECTORY_SEPARATOR . $relativeTarget;
    }

    /**
     * Ensures that the given directory exists (creating it recursively if needed).
     */
    private static function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create directory: {$directory}");
        }
    }

    private static function sanitizeRelativeTarget(string $target, string $default): string
    {
        $target = trim($target);
        if ($target === '') {
            return $default;
        }

        $lastChar = substr($target, -1);
        if ($lastChar === '/' || $lastChar === '\\') {
            $target .= $default;
        }

        $normalized = str_replace('\\', '/', $target);
        $normalized = ltrim($normalized, '/');

        if ($normalized === '') {
            return $default;
        }

        $parts = array_filter(explode('/', $normalized), static function ($part) {
            return $part !== '' && $part !== '.';
        });

        foreach ($parts as $part) {
            if ($part === '..') {
                throw new \RuntimeException('Output file paths cannot traverse directories.');
            }
        }

        $sanitized = implode(DIRECTORY_SEPARATOR, $parts);

        return $sanitized !== '' ? $sanitized : $default;
    }

    private static function normalizeRelativeDirectory(string $directory, string $root): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedDirectory = rtrim(str_replace('\\', '/', $directory), '/');

        if (strpos($normalizedDirectory, $normalizedRoot) !== 0) {
            return '';
        }

        $relative = substr($normalizedDirectory, strlen($normalizedRoot));
        $relative = ltrim($relative, '/');

        if ($relative === '') {
            return '';
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private static function getFrontMatter(string $mdContent): array {



        $vars = [];

        if (!preg_match('/^---\s*\n(.*?)\n---\s*(?:\n|$)/s', $mdContent, $matches)) {
            return $vars;
        }

        $frontMatter = $matches[1];

        foreach (preg_split('/\r?\n/', $frontMatter) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");

            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }
}
