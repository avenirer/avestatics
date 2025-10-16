<?php
namespace Avenirer\AveStatics;

class Statics
{

    public static function build(?bool $force = null): void {

        $previousForce = BuildOptions::isForce();
        if ($force !== null) {
            BuildOptions::setForce($force);
        }

        Component::buildComponents();
        Layout::buildLayouts();
        $contentDir = PathResolver::getContentDir();
        if (!is_dir($contentDir)) {
            return;
        }

        $directoryIterator = new \RecursiveDirectoryIterator($contentDir, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directoryIterator);
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }
            if (strtolower($fileinfo->getExtension()) !== 'md') {
                continue;
            }
            
            Markdown::buildHtml($fileinfo);
        }

        if ($force !== null) {
            BuildOptions::setForce($previousForce);
        }
    }
    
}
