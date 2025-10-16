<?php
namespace Avenirer\AveStatics;

class Component
{

    public static function buildComponents()
    {
        $componentsDir = PathResolver::getLayoutsComponentsDir();

        if (!is_dir($componentsDir)) {
            return;
        }

        $iterator = new \DirectoryIterator($componentsDir);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot() || !$fileinfo->isDir()) {
                continue;
            }
            $path = $fileinfo->getPathname();
            Component::buildComponent($path);
            echo "Building component: {$path}\n";
        }
        
    }

    public static function buildComponent(string $path): void {
        $builtComponentsDir = PathResolver::getLayoutsBuiltComponentsDir();

        if (!is_dir($builtComponentsDir)) {
            PathResolver::ensureDirectory($builtComponentsDir);
        }

        $componentName = basename($path);
        $srcHtml = $path . DIRECTORY_SEPARATOR . $componentName . '.html';
        $srcCss = $path . DIRECTORY_SEPARATOR . $componentName . '.css';
        $srcJs = $path . DIRECTORY_SEPARATOR . $componentName . '.js';

        $destHtml = $builtComponentsDir . DIRECTORY_SEPARATOR . $componentName . '.html';
        $destCss = $builtComponentsDir . DIRECTORY_SEPARATOR . $componentName . '.css';
        $destJs = $builtComponentsDir . DIRECTORY_SEPARATOR . $componentName . '.js';

        // If HTML already exists in built, skip moving (unless forcing)
        if (is_file($destHtml) && !BuildOptions::isForce()) {
            return;
        }

        // Move available files (html, css, js) from component folder to built/components
        if (is_file($srcHtml)) {
            copy($srcHtml, $destHtml);
        }
        if (is_file($srcCss)) {
            if (!BuildOptions::isForce() && is_file($destCss) && filemtime($destCss) >= filemtime($srcCss)) {
                // already up to date
            } else {
                copy($srcCss, $destCss);
            }
        }
        if (is_file($srcJs)) {
            if (!BuildOptions::isForce() && is_file($destJs) && filemtime($destJs) >= filemtime($srcJs)) {
                // already up to date
            } else {
                copy($srcJs, $destJs);
            }
        }
    }
}
