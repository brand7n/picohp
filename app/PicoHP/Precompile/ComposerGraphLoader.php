<?php

declare(strict_types=1);

namespace App\PicoHP\Precompile;

/**
 * Loads Composer-generated autoload maps from vendor/composer/.
 */
final class ComposerGraphLoader
{
    public function load(string $projectRoot): ComposerAutoloadGraph
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $vendorComposer = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer';

        $classmapPath = $vendorComposer . DIRECTORY_SEPARATOR . 'autoload_classmap.php';
        if (!is_file($classmapPath)) {
            throw new \RuntimeException("Composer classmap not found: {$classmapPath}");
        }

        /** @var array<string, string> */
        $classmap = require $classmapPath;

        $psr4Path = $vendorComposer . DIRECTORY_SEPARATOR . 'autoload_psr4.php';
        /** @var array<string, list<string>> */
        $psr4 = is_file($psr4Path) ? require $psr4Path : [];

        $filesPath = $vendorComposer . DIRECTORY_SEPARATOR . 'autoload_files.php';
        /** @var array<string, string> */
        $filesMap = is_file($filesPath) ? require $filesPath : [];
        $autoloadFiles = array_values($filesMap);

        return new ComposerAutoloadGraph($classmap, $psr4, $autoloadFiles);
    }
}
