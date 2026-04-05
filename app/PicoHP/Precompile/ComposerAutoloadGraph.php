<?php

declare(strict_types=1);

namespace App\PicoHP\Precompile;

/**
 * Snapshot of Composer autoload metadata for a project (loaded from vendor/composer/*.php).
 */
final class ComposerAutoloadGraph
{
    /**
     * @param array<string, string> $classmap Fully-qualified class name => absolute file path
     * @param array<string, list<string>> $psr4NamespacePrefixes PSR-4 prefix => list of absolute directory roots
     * @param list<string> $autoloadFiles Files Composer loads on every request (from autoload_files.php)
     * @param array<string, string> $classPathOverrides FQCN => absolute path; wins over Composer class resolution and snapshot classmap during reachability
     */
    public function __construct(
        public array $classmap = [],
        public array $psr4NamespacePrefixes = [],
        public array $autoloadFiles = [],
        public array $classPathOverrides = [],
    ) {
    }
}
