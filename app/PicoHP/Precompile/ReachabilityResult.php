<?php

declare(strict_types=1);

namespace App\PicoHP\Precompile;

/**
 * Output of {@see ReachabilityAnalyzer}: files discovered from entrypoints and unresolved class names.
 */
final class ReachabilityResult
{
    /**
     * @param list<string> $reachableFiles Absolute paths (entry, classmap targets, required PHP files)
     * @param list<string> $unresolvedClassReferences FQCNs seen as {@see \PhpParser\Node\Name\FullyQualified} but not in Composer classmap
     */
    public function __construct(
        public array $reachableFiles = [],
        public array $unresolvedClassReferences = [],
    ) {
    }
}
