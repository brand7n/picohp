<?php

declare(strict_types=1);

namespace App\PicoHP\Precompile;

/**
 * Result of precompile planning: ordered compile inputs and diagnostics.
 */
final class CompilationPlan
{
    /**
     * @param list<string> $entrypoints Absolute paths to declared entry files (e.g. src/main.php)
     * @param list<string> $orderedFiles Compile order for directory {@code build} (same as reachable files, BFS from entry)
     * @param list<string> $classmapFiles Unique absolute paths from Composer classmap (excluding InstalledVersions)
     * @param list<string> $reachableFiles Absolute paths reachable from entrypoints (AST + classmap + literal requires)
     * @param list<string> $prunedFiles Classmap paths not reachable from entrypoints
     * @param list<string> $unresolvedClassReferences FQCNs seen in code but not in Composer classmap
     * @param list<string> $notes Planner caveats
     * @param array<string, string> $classPathOverrides FQCN => absolute path from {@code --override-class}
     */
    public function __construct(
        public array $entrypoints = [],
        public array $orderedFiles = [],
        public array $classmapFiles = [],
        public array $reachableFiles = [],
        public array $prunedFiles = [],
        public array $unresolvedClassReferences = [],
        public array $notes = [],
        public array $classPathOverrides = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entrypoints' => $this->entrypoints,
            'ordered_files' => $this->orderedFiles,
            'classmap_files' => $this->classmapFiles,
            'reachable_files' => $this->reachableFiles,
            'pruned_files' => $this->prunedFiles,
            'unresolved_class_references' => $this->unresolvedClassReferences,
            'notes' => $this->notes,
            'class_path_overrides' => $this->classPathOverrides,
        ];
    }
}
