<?php
namespace Avenirer\AveStatics;

class LayoutList
{
    /**
     * Extracts parameters from an <x-f-list ...>...</x-f-list> sequence.
     *
     * @return array<string,string>|int  Parameter map or 0 when not found.
     */
    public static function build(\SplFileInfo $fileinfo, string $layout, $mdFrontmatter)
    {
        if (!preg_match('/<x-f-list\b([^>]*)>(.*?)<\/x-f-list>/is', $layout, $matches)) {
            return 0;
        }

        $attributeString = $matches[1] ?? '';
        $content = $matches[2] ?? '';
        $toReplace = $matches[0];

        $parameters = [];
        if ($attributeString !== '') {
            if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_\-]*)\s*=\s*([\'\"])(.*?)\2/', $attributeString, $attributeMatches, PREG_SET_ORDER)) {
                foreach ($attributeMatches as $match) {
                    $key = $match[1];
                    $value = $match[3];
                    $parameters[$key] = $value;
                }
            }
        }

        $trimmedContent = trim($content);
        if ($trimmedContent !== '') {
            $parameters['content'] = $trimmedContent;
        }

        if(!isset($parameters['use'])) {
            echo "No component was defined for the layout list on {$fileinfo->getFilename()}";
            return false;
        }

        $componentsDir = PathResolver::getLayoutsComponentsDir();
        $builtComponentsDir = PathResolver::getLayoutsBuiltComponentsDir();

        $builtComponentPath = $builtComponentsDir . DIRECTORY_SEPARATOR . $parameters['use'] . '.html';

        // If built component not available, try to build from source if exists
        if (!is_file($builtComponentPath)) {
            $sourceComponentPath = $componentsDir . DIRECTORY_SEPARATOR . $parameters['use'];
            if (is_dir($sourceComponentPath) && class_exists(\Avenirer\AveStatics\Component::class)) {
                \Avenirer\AveStatics\Component::buildComponent($sourceComponentPath);
            }
        }

        if (!is_file($builtComponentPath)) {
            echo "Couldn't find the component content for {$parameters['use']}";
            return false;
        }

        $componentHtml = file_get_contents($builtComponentPath);
        if (strlen(trim($componentHtml)) === 0) {
            echo "Couldn't find the component content for {$parameters['use']}";
            return false;
        }

        $archivePublicDir = str_replace(PathResolver::getContentDir(), PathResolver::getPublicDir(), $fileinfo->getPath());
        $fileName = array_key_exists('file', $mdFrontmatter) ? explode('.', $mdFrontmatter['file'])[0] : str_replace('.'.$fileinfo->getExtension(), '', $fileinfo->getFilename());

        $archiveDir = $fileinfo->getPath();
        $directoryIterator = new \DirectoryIterator($archiveDir);

        $items = [];

        foreach ($directoryIterator as $entry) {
            $data = [];

            if ($entry->isDot() || !$entry->isFile() || $entry->getFilename() == $fileinfo->getFilename()) {
                continue;
            }

            if (strtolower($entry->getExtension()) !== 'md') {
                continue;
            }

            $data['timestamp'] = $entry->getCTime();

            $mdContent = file_get_contents($entry->getPathname());
            if ($mdContent === false) {
                continue;
            }

            $frontMatter = self::extractFrontMatter($mdContent);
            if (empty($frontMatter)) {
                continue;
            }

            
            foreach ($frontMatter as $key => $value) {
                if (strpos($key, 'use-') === 0) {
                    $data['placeholders'][str_replace('use-', '', $key)] = $value;
                }
            }

            $file = array_key_exists('file', $frontMatter) ? $frontMatter['file'] : str_replace('.md', '.html', $entry->getFilename());
            $data['FILE'] = $file;
            $archiveRootUrl = str_replace(PathResolver::getPublicDir(), PathResolver::getBaseUrl(), $archivePublicDir);
            $data['FILE'] = PathResolver::joinPaths($archiveRootUrl, $data['FILE']);

            if(isset($data['placeholders'])) {
                $items[$entry->getFilename()] = $data;
            }
        }

        $itemsPerPage = intval($parameters['itemsperpage']) ?? 20;
        $orderBy = $parameters['orderby'] ?? 'timestamp desc';
        
        
        

        $pagesBuilt = self::buildListPages($fileName, $layout, $toReplace, $componentHtml, $items, $archivePublicDir, $itemsPerPage, $orderBy);

        var_dump($pagesBuilt);
        exit;

    }
    

    public static function buildListPages($archiveFileName, $layout, $toReplace, $componentHtml, $filesData, $archivePublicDir,  $itemsPerPage = 20, $orderBy = 'datetime desc') {


        $sortOrder = strpos($orderBy, 'asc') !== false ? SORT_ASC : SORT_DESC;
        $orderBy = strpos($orderBy, 'timestamp') === 0 ? 'timestamp' : 'file';
        
        array_multisort(array_column($filesData, 'timestamp'), $sortOrder, $filesData);

        $i = 1;
        $listingContent = '';
        foreach($filesData as $entry) {
            

            //var_dump($entry);
            if($i == $itemsPerPage) {

                if($i == $itemsPerPage) {
                    
                    $archives[] = $listingContent;
                    //self::buildArchiveFile()
                    $listingContent = '';
                    $i = 1;
                }
                
            }

            $updatedComponent = str_replace('{{FILE}}', $entry['FILE'], $componentHtml);

            $listingContent .= Markdown::applyParams($updatedComponent, $entry['placeholders']);

            

            $i++;
        }

        if (trim($listingContent != '')) {
            $updatedLayout = self::replaceListingBlock($layout, $listingContent);
            $archives[] = $listingContent;
        }

        if(!empty($archives)) {
            return self::buildArchivePages($archives, $layout, $toReplace, $archiveFileName, $archivePublicDir, $itemsPerPage);
        }

        return 0;
    }

    private static function buildArchivePages(array $archives, string $layout, string $toReplace, string $archiveFileName, string $archivePublicDir, int $itemsPerPage) : int {
        $totalPages = sizeof($archives);


        $i = 0;
        foreach($archives as $content) {
            $updatedLayout = self::replaceListingBlock($layout, $content);
            if(strlen(trim($updatedLayout)) > 0) {
                PathResolver::ensureDirectory($archivePublicDir);
                $fileName = $archiveFileName . (($i>0) ? '-'.($i+1) : '') . '.html';

                $outputPath = PathResolver::joinPaths($archivePublicDir, $fileName);
                
                if (file_put_contents($outputPath, $updatedLayout) === false) {
                    echo "Failed to write generated HTML to {$outputPath}\n";
                    return $i+1;
                }

                $i++;
                
            }

        }
        return $i;
    }

    private static function extractFrontMatter(string $content): array
    {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*(?:\n|$)/s', $content, $matches)) {
            return [];
        }

        $frontMatter = [];
        foreach (preg_split('/\r?\n/', $matches[1]) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value, " \"'\t\r\n");

            if ($key !== '') {
                $frontMatter[$key] = $value;
            }
        }

        return $frontMatter;
    }

    private static function replaceListingBlock(string $layout, string $listingContent): string
    {
        $pattern = '/<x-f-list\b[^>]*>.*?<\/x-f-list>/is';
        $result = preg_replace($pattern, $listingContent, $layout, 1);
        return $result !== null ? $result : $layout;
    }
}
