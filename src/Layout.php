<?php
namespace Avenirer\AveStatics;

class Layout
{
    public static function buildLayouts(): void
    {
        $layoutsDir = PathResolver::getLayoutsViewsDir();

        if (!is_dir($layoutsDir)) {
            return;
        }

        $iterator = new \DirectoryIterator($layoutsDir);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot() || !$fileinfo->isDir()) {
                continue;
            }
            $path = $fileinfo->getPathname();
            $builtPath = Layout::buildLayout($path);

            if ($builtPath !== null) {
                echo "Built layout: {$builtPath}\n";
            }
        }
        
    }

    public static function buildLayout(string $path): ?string {
        $builtLayoutsDir = PathResolver::getLayoutsBuiltViewsDir();

        try {
            PathResolver::ensureDirectory($builtLayoutsDir);
        } catch (\RuntimeException $e) {
            echo $e->getMessage() . "\n";
            return null;
        }

        $layoutName = basename($path);
        $srcHtml = $path . DIRECTORY_SEPARATOR . $layoutName . '.html';
        $srcCss = $path . DIRECTORY_SEPARATOR . $layoutName . '.css';
        $srcJs = $path . DIRECTORY_SEPARATOR . $layoutName . '.js';

        $destHtml = $builtLayoutsDir . DIRECTORY_SEPARATOR . $layoutName . '.html';
        $destCss = $builtLayoutsDir . DIRECTORY_SEPARATOR . $layoutName . '.css';
        $destJs = $builtLayoutsDir . DIRECTORY_SEPARATOR . $layoutName . '.js';

        // If HTML exists in built and is newer or same as source, skip processing
        $force = BuildOptions::isForce();
        if (!$force && is_file($destHtml) && is_file($srcHtml) && filemtime($destHtml) >= filemtime($srcHtml)) {
            self::syncAsset($srcCss, $destCss);
            self::syncAsset($srcJs, $destJs);
            return $destHtml;
        }

        if (!is_file($srcHtml)) {
            echo "Source layout HTML missing for {$layoutName} at {$srcHtml}\n";
            return null;
        }

        // Move available files (html, css, js) from Layout folder to built/Layouts
        $htmlContent = file_get_contents($srcHtml);
        if ($htmlContent === false) {
            echo "Failed to read layout HTML from {$srcHtml}\n";
            return null;
        }

        $updatedContent = self::updateLayoutContent($htmlContent);
        $updatedContent = self::applyAssetPlaceholders($updatedContent, $layoutName, $path);

        if (file_put_contents($destHtml, $updatedContent) === false) {
            echo "Failed to write built layout HTML to {$destHtml}\n";
            return null;
        }

        self::syncAsset($srcCss, $destCss);
        self::syncAsset($srcJs, $destJs);

        return $destHtml;
    }

    public static function updateLayoutContent(string $content): string
    {
        // Detect all usage patterns: <x-use "LayoutName"> ... </x-use>
        if (preg_match_all('/<x-use\s+"([^"]+)">([\s\S]*?)<\/x-use>/si', $content, $allUses, PREG_SET_ORDER)) {
            foreach ($allUses as $useMatches) {
                $layoutName = $useMatches[1];
                $innerContent = $useMatches[2];

                $usedLayoutPath = self::ensureLayoutBuilt($layoutName);
                if (!$usedLayoutPath || !is_file($usedLayoutPath)) {
                    continue;
                }

                $usedLayoutHtml = file_get_contents($usedLayoutPath);

                if(strlen(trim($usedLayoutHtml)) == 0) {
                    continue;
                }

                preg_match_all('/<x-content-([a-zA-Z0-9_\-]+)(\s+[^>]*)?>([\s\S]*?)<\/x-content-\1>/si', $innerContent, $templateBlocks, PREG_SET_ORDER);

                $innerBlocks = [];
                if (!empty($templateBlocks)) {
                    foreach($templateBlocks as $block) {
                        $innerBlocks[$block[1]] = [
                            'key' => $block[1],
                            'params' => $block[2],
                            'content' => $block[3],
                            'blockContent' => $block[0],
                        ];
                    }
                }

                // Collect <x-content-...> blocks (with optional attributes) and inject them into the used layout
                if (preg_match_all('/<x-content-([a-zA-Z0-9_\-]+)(\s+[^>]*)?>([\s\S]*?)<\/x-content-\1>/si', $usedLayoutHtml, $placeholderBlocks, PREG_SET_ORDER)) {                    
                    foreach ($placeholderBlocks as $block) {
                        $blockKey = $block[1];
                        $blockParams = $block[2];
                        $blockFallbackContent = $block[3];
                        $blockContent = $block[0];

                        if(isset($innerBlocks[$blockKey]) && strlen(trim($innerBlocks[$blockKey]['content'])) > 0) {
                            $usedLayoutHtml = str_replace($blockContent, $innerBlocks[$blockKey]['content'], $usedLayoutHtml);
                        } else {
                            $usedLayoutHtml = str_replace($blockContent, $blockFallbackContent, $usedLayoutHtml);
                        }
                    }
                }

                $content = str_replace($useMatches[0], $usedLayoutHtml, $content);
            }
        }

        $content = self::includeComponents($content);

        // Keep returning original until we decide to persist merged HTML
        return $content;
    }

    public static function ensureLayoutBuilt(string $layout): ?string
    {
        $sourceDir = self::resolveLayoutSourceDir($layout);
        if ($sourceDir === null) {
            echo "Layout source directory missing for {$layout}\n";
            return null;
        }

        $layoutName = basename($sourceDir);
        $builtPath = PathResolver::joinPaths(PathResolver::getLayoutsBuiltViewsDir(), $layoutName . '.html');

        $shouldRebuild = BuildOptions::isForce() || !is_file($builtPath);
        if (!$shouldRebuild) {
            $sourceHtml = PathResolver::joinPaths($sourceDir, $layoutName . '.html');
            $sourceCss = PathResolver::joinPaths($sourceDir, $layoutName . '.css');
            $sourceJs = PathResolver::joinPaths($sourceDir, $layoutName . '.js');

            $sourceTimes = array_filter([
                is_file($sourceHtml) ? filemtime($sourceHtml) : null,
                is_file($sourceCss) ? filemtime($sourceCss) : null,
                is_file($sourceJs) ? filemtime($sourceJs) : null,
            ]);

            if (!empty($sourceTimes)) {
                $latestSource = max($sourceTimes);
                $shouldRebuild = $latestSource > filemtime($builtPath);
            }
        }

        if ($shouldRebuild) {
            return self::buildLayout($sourceDir);
        }

        return $builtPath;
    }

    public static function includeComponents(string $layout): string
    {
        $componentsDir = PathResolver::getLayoutsComponentsDir();
        $builtComponentsDir = PathResolver::getLayoutsBuiltComponentsDir();

        // Find all component usages like: <x-c-Name ...> ... </x-c-Name>
		if (preg_match_all('/<x-c-([a-zA-Z0-9_\-]+)(\s+[^>]*)?>([\s\S]*?)<\/x-c-\1>/si', $layout, $componentUses, PREG_SET_ORDER)) {

            foreach ($componentUses as $use) {
                $componentName = $use[1];
                $innerContent = $use[3];

                $builtComponentPath = $builtComponentsDir . DIRECTORY_SEPARATOR . $componentName . '.html';

                // If built component not available, try to build from source if exists
                if (!is_file($builtComponentPath)) {
                    $sourceComponentPath = $componentsDir . DIRECTORY_SEPARATOR . $componentName;
                    if (is_dir($sourceComponentPath) && class_exists(\Avenirer\AveStatics\Component::class)) {
                        \Avenirer\AveStatics\Component::buildComponent($sourceComponentPath);
                    }
                }

                if (!is_file($builtComponentPath)) {
                    continue;
                }

                $componentHtml = file_get_contents($builtComponentPath);
                if (strlen(trim($componentHtml)) === 0) {
                    continue;
                }

				$calledComponentParamsString = trim($use[2]);
				$calledComponentParams = self::parseAttributes($calledComponentParamsString);

				// Replace placeholders using called component params
				$componentHtml = self::applyComponentParams($componentHtml, $calledComponentParams);

                // Map inner provided content blocks by key
                $innerBlocks = [];
                if (preg_match_all('/<x-content-([a-zA-Z0-9_\-]+)(\s+[^>]*)?>([\s\S]*?)<\/x-content-\1>/si', $innerContent, $templateBlocks, PREG_SET_ORDER)) {
                    foreach ($templateBlocks as $block) {
                        $innerBlocks[$block[1]] = [
                            'key' => $block[1],
							'params' => $block[2],
                            'content' => $block[3],
                            'blockContent' => $block[0],
                        ];
                    }
                }

                // Replace placeholders in component HTML with provided blocks or fallback
                if (preg_match_all('/<x-content-([a-zA-Z0-9_\-]+)(\s+[^>]*)?>([\s\S]*?)<\/x-content-\1>/si', $componentHtml, $placeholderBlocks, PREG_SET_ORDER)) {
                    foreach ($placeholderBlocks as $block) {
                        $blockKey = $block[1];
                        $blockFallbackContent = $block[3];
                        $blockContentFull = $block[0];

						if (isset($innerBlocks[$blockKey]) && strlen(trim($innerBlocks[$blockKey]['content'])) > 0) {
                            $componentHtml = str_replace($blockContentFull, $innerBlocks[$blockKey]['content'], $componentHtml);
                        } else {
                            $componentHtml = str_replace($blockContentFull, $blockFallbackContent, $componentHtml);
                        }
                    }
                }

                // Replace entire use tag with the fully composed component HTML
				$layout = str_replace($use[0], $componentHtml, $layout);
            }
        }

		return $layout;
    }

	private static function parseAttributes(string $attributes): array
	{
		$attributes = trim($attributes);
		if ($attributes === '') {
			return [];
		}
		$parsed = [];
		// Supports key="value" or key='value', ignores leading spaces
		if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_:\\-]*)\s*=\s*("([^"]*)"|\'([^\']*)\')/u', $attributes, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$key = $m[1];
				$value = isset($m[3]) && $m[3] !== '' ? $m[3] : ($m[4] ?? '');
				$parsed[$key] = $value;
			}
		}
		return $parsed;
	}

	private static function applyComponentParams(string $html, array $params): string
	{
		if (empty($params)) {
			return $html;
		}
		return preg_replace_callback('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_:\\-]*)\s*(?:\|\s*([^}]*))?\s*\}\}/', function($m) use ($params) {
			$key = $m[1];
			if (array_key_exists($key, $params)) {
				return $params[$key];
			}
			return $m[0];
		}, $html);
	}

    private static function syncAsset(string $source, string $destination): void
    {
        if (!is_file($source)) {
            return;
        }

        if (!BuildOptions::isForce() && is_file($destination) && filemtime($destination) >= filemtime($source)) {
            return;
        }

        if (!copy($source, $destination)) {
            echo "Failed to copy asset from {$source} to {$destination}\n";
        }
    }

    public static function getLayoutSourceDir(string $layout): ?string
    {
        return self::resolveLayoutSourceDir($layout);
    }

    private static function resolveLayoutSourceDir(string $layout): ?string
    {
        $layoutsRoot = PathResolver::getLayoutsViewsDir();
        $normalized = trim($layout);
        if ($normalized === '') {
            return null;
        }

        $directPath = PathResolver::joinPaths($layoutsRoot, $normalized);
        if (is_dir($directPath)) {
            return $directPath;
        }

        if (!is_dir($layoutsRoot)) {
            return null;
        }

        $target = strtolower($normalized);
        $iterator = new \DirectoryIterator($layoutsRoot);
        foreach ($iterator as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            if (strtolower($entry->getFilename()) === $target) {
                return $entry->getPathname();
            }
        }

        return null;
    }

    private static function applyAssetPlaceholders(string $content, string $layoutName, string $layoutDir): string
    {
        $jsFileName = $layoutName . '.js';
        $cssFileName = $layoutName . '.css';
        $jsSourcePath = PathResolver::joinPaths($layoutDir, $jsFileName);
        $cssSourcePath = PathResolver::joinPaths($layoutDir, $cssFileName);

        $content = self::replaceJsPlaceholders($content, $jsSourcePath, $jsFileName);
        $content = self::replaceCssPlaceholders($content, $cssSourcePath, $cssFileName);

        return $content;
    }

    private static function replaceJsPlaceholders(string $content, string $sourcePath, string $outputFileName): string
    {
        $callback = function (array $matches) use ($sourcePath, $outputFileName): string {
            $attributeString = $matches[1] ?? '';
            $innerContent = $matches[2] ?? '';
            $attributes = self::parseAttributes($attributeString);
            $location = array_key_exists('location', $attributes) ? trim((string) $attributes['location']) : '';
            $lowerLocation = strtolower($location);
            $hasInlineContent = trim($innerContent) !== '';
            $shouldInline = ($lowerLocation === 'inline') || ($location === '' && $hasInlineContent);
            $dataAttr = $location !== '' ? ' data-x-location="' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '"' : '';

            $scriptBody = '';
            if ($shouldInline) {
                if ($hasInlineContent) {
                    $scriptBody = $innerContent;
                } elseif (is_file($sourcePath)) {
                    $scriptBody = file_get_contents($sourcePath) ?: '';
                } else {
                    echo "Missing source JS for inline placeholder: {$sourcePath}\n";
                }
                return "<script{$dataAttr}>{$scriptBody}</script>";
            }

            if (!is_file($sourcePath)) {
                echo "Missing source JS for placeholder: {$sourcePath}\n";
                return '';
            }

            $srcAttr = htmlspecialchars($outputFileName, ENT_QUOTES, 'UTF-8');
            return "<script src=\"{$srcAttr}\"{$dataAttr}></script>";
        };

        // Handle paired tags first
        // Handle self-closing tags first to avoid greedy matches
        $content = preg_replace_callback('/<x-js\b([^>]*)\/>/i', function ($matches) use ($callback) {
            return $callback([$matches[0], $matches[1], '']);
        }, $content);

        return preg_replace_callback('/<x-js\b([^>]*)>(.*?)<\/x-js\s*>/is', $callback, $content);
    }

    private static function replaceCssPlaceholders(string $content, string $sourcePath, string $outputFileName): string
    {
        $callback = function (array $matches) use ($sourcePath, $outputFileName): string {
            $attributeString = $matches[1] ?? '';
            $innerContent = $matches[2] ?? '';
            $attributes = self::parseAttributes($attributeString);
            $location = array_key_exists('location', $attributes) ? trim((string) $attributes['location']) : '';
            $lowerLocation = strtolower($location);
            $dataAttr = $location !== '' ? ' data-x-location="' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '"' : '';

            $cssContent = '';
            $hasInlineContent = trim($innerContent) !== '';
            $shouldInline = ($lowerLocation === 'inline') || ($location === '' && $hasInlineContent);

            if ($shouldInline) {
                if ($hasInlineContent) {
                    $cssContent = $innerContent;
                } elseif (is_file($sourcePath)) {
                    $cssContent = file_get_contents($sourcePath) ?: '';
                } else {
                    echo "Missing source CSS for inline placeholder: {$sourcePath}\n";
                }
                return "<style{$dataAttr}>{$cssContent}</style>";
            }

            if (!is_file($sourcePath)) {
                echo "Missing source CSS for placeholder: {$sourcePath}\n";
                return '';
            }

            $hrefAttr = htmlspecialchars($outputFileName, ENT_QUOTES, 'UTF-8');
            return "<link rel=\"stylesheet\" href=\"{$hrefAttr}\"{$dataAttr} />";
        };

        $content = preg_replace_callback('/<x-css\b([^>]*)\/>/i', function ($matches) use ($callback) {
            return $callback([$matches[0], $matches[1], '']);
        }, $content);

        return preg_replace_callback('/<x-css\b([^>]*)>(.*?)<\/x-css\s*>/is', $callback, $content);
    }

    public static function applySS(string $layoutContent, ?string $layoutDir = null): string
    {
        if (stripos($layoutContent, 'data-x-location') === false) {
            return $layoutContent;
        }

        if ($layoutDir === null) {
            return preg_replace('/\sdata-x-location="[^"]*"/i', '', $layoutContent);
        }

        $header = [];
        $footer = [];
        $order = 0;

        $collect = function (array $matches) use (&$header, &$footer, &$order, $layoutDir) {
            $fullTag = $matches[0];
            $tagName = strtolower($matches['tag']);
            $locationRaw = strtolower(trim($matches['loc']));

            $sanitized = preg_replace('/\sdata-x-location="[^"]*"/i', '', $fullTag);

            if ($locationRaw === '' || $locationRaw === 'inline' || !preg_match('/^(header|footer)(?:-(\d+))?$/', $locationRaw, $locParts)) {
                return $sanitized;
            }

            $region = $locParts[1];
            $weight = isset($locParts[2]) ? (int) $locParts[2] : 100;

            $sanitized = self::rewriteAssetReferences($sanitized, $tagName, $layoutDir);

            $record = [
                'markup' => trim($sanitized),
                'weight' => $weight,
                'order' => $order++,
            ];

            if ($region === 'header') {
                $header[] = $record;
            } else {
                $footer[] = $record;
            }

            return '';
        };

        $pairedPattern = '/<(?P<tag>[a-zA-Z][a-zA-Z0-9:-]*)(?P<before>[^>]*?)data-x-location="(?P<loc>[^"]+)"(?P<after>[^>]*)>(?P<content>.*?)<\/\1>/is';
        $selfPattern = '/<(?P<tag>[a-zA-Z][a-zA-Z0-9:-]*)(?P<before>[^>]*?)data-x-location="(?P<loc>[^"]+)"(?P<after>[^>]*)\/>/is';

        $layoutContent = preg_replace_callback($pairedPattern, function ($m) use ($collect) {
            return $collect($m);
        }, $layoutContent);

        $layoutContent = preg_replace_callback($selfPattern, function ($m) use ($collect) {
            return $collect($m);
        }, $layoutContent);

        $layoutContent = self::injectCollectedAssets($layoutContent, $header, '</head>');
        $layoutContent = self::injectCollectedAssets($layoutContent, $footer, '</body>');

        return $layoutContent;
    }

    private static function rewriteAssetReferences(string $markup, string $tagName, string $layoutDir): string
    {
        if ($tagName === 'script') {
            return preg_replace_callback('/\bsrc="([^"]+)"/i', function ($m) use ($layoutDir) {
                $new = self::rewriteAssetPath($m[1], 'js', $layoutDir);
                return $new ? 'src="' . $new . '"' : $m[0];
            }, $markup, 1);
        }

        if ($tagName === 'link' && preg_match('/\brel="stylesheet"/i', $markup)) {
            return preg_replace_callback('/\bhref="([^"]+)"/i', function ($m) use ($layoutDir) {
                $new = self::rewriteAssetPath($m[1], 'css', $layoutDir);
                return $new ? 'href="' . $new . '"' : $m[0];
            }, $markup, 1);
        }

        return $markup;
    }

    private static function rewriteAssetPath(string $value, string $subdir, string $layoutDir): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('#^(?:https?:)?//#i', $trimmed)) {
            return null;
        }

        $normalized = ltrim($trimmed, './\\');
        $searchRoots = [$layoutDir];

        $builtViewsDir = PathResolver::getLayoutsBuiltViewsDir();
        if ($builtViewsDir !== '' && is_dir($builtViewsDir)) {
            $searchRoots[] = $builtViewsDir;
        }

        $sourcePath = null;
        foreach ($searchRoots as $root) {
            $candidate = PathResolver::joinPaths($root, $normalized);
            if (is_file($candidate)) {
                $sourcePath = $candidate;
                break;
            }
        }

        if ($sourcePath === null) {
            echo "Asset not found for {$value} (searched in: " . implode(', ', $searchRoots) . ")\n";
            return null;
        }

        $publicDir = PathResolver::getPublicDir();
        $assetsDir = PathResolver::joinPaths($publicDir, 'assets');
        $targetDir = PathResolver::joinPaths($assetsDir, $subdir);
        PathResolver::ensureDirectory($targetDir);

        $filename = strtolower(basename($normalized));
        $destination = PathResolver::joinPaths($targetDir, $filename);

        if (BuildOptions::isForce() && is_file($destination)) {
            @unlink($destination);
        }

        if (BuildOptions::isForce() || !is_file($destination) || filemtime($destination) < filemtime($sourcePath)) {
            if (!copy($sourcePath, $destination)) {
                echo "Failed to copy asset from {$sourcePath} to {$destination}\n";
                return null;
            }
        }

        return 'assets/' . $subdir . '/' . $filename;
    }

    private static function injectCollectedAssets(string $html, array $items, string $closingTag): string
    {
        if (empty($items)) {
            return $html;
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['weight'] === $b['weight']) {
                return $a['order'] <=> $b['order'];
            }
            return $a['weight'] <=> $b['weight'];
        });

        $formatted = array_map(static function (array $item) {
            return self::indentMarkup($item['markup'], 4);
        }, $items);

        $injection = "\n" . implode("\n", $formatted) . "\n";
        $pattern = sprintf('/%s/i', preg_quote($closingTag, '/'));

        if (preg_match($pattern, $html, $match)) {
            return preg_replace($pattern, $injection . $match[0], $html, 1);
        }

        return $html . $injection;
    }

    private static function indentMarkup(string $markup, int $spaces): string
    {
        $indent = str_repeat(' ', $spaces);
        $trimmed = trim($markup);
        $indented = preg_replace('/\r?\n/', "\n" . $indent, $trimmed);
        return $indent . $indented;
    }
}
