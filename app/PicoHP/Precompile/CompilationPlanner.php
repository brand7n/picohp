<?php

declare(strict_types=1);

namespace App\PicoHP\Precompile;

/**
 * Builds a {@see CompilationPlan} from Composer autoload data and reachability analysis.
 *
 * **Pruned** = classmap file paths that never appear in the reachable closure from entrypoints.
 * Reachability is computed by parsing each visited file with php-parser, resolving names, following
 * {@see \PhpParser\Node\Name\FullyQualified} references into the Composer classmap, and enqueueing
 * literal string {@code require}/{@code include} targets.
 */
final class CompilationPlanner
{
    public function __construct(
        private readonly ComposerGraphLoader $composerGraphLoader = new ComposerGraphLoader(),
        private readonly ?ReachabilityAnalyzer $reachabilityAnalyzer = null,
    ) {
    }

    /**
     * @param string $entryPhpAbsolute Absolute path to the project entry PHP file (e.g. {@code .../src/main.php} or {@code .../picohp})
     */
    public function planDirectoryBuild(string $projectRoot, string $entryPhpAbsolute): CompilationPlan
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $graph = $this->composerGraphLoader->load($projectRoot);

        $mainReal = realpath($entryPhpAbsolute);
        if ($mainReal === false || !is_file($mainReal)) {
            throw new \RuntimeException("Entry point not found: {$entryPhpAbsolute}");
        }

        $entrypoints = [$mainReal];

        $seen = [];
        $fromClassmap = [];

        foreach ($graph->classmap as $className => $file) {
            if ($className === 'Composer\InstalledVersions') {
                continue;
            }
            $real = realpath($file);
            if ($real === false) {
                continue;
            }
            if (isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            $fromClassmap[] = $real;
        }

        sort($fromClassmap);
        $classmapFiles = $fromClassmap;

        $analyzer = $this->reachabilityAnalyzer ?? ReachabilityAnalyzer::createDefault();
        $reach = $analyzer->analyze($graph, $entrypoints, $projectRoot);
        $reachableFiles = $reach->reachableFiles;

        $reachableSet = array_flip($reachableFiles);
        $pruned = [];
        foreach ($classmapFiles as $path) {
            if (!isset($reachableSet[$path])) {
                $pruned[] = $path;
            }
        }

        $orderedFiles = $reachableFiles;

        $notes = [
            'Reachable files = static closure from the entrypoint (BFS over FQCN references via Composer autoload and literal require/include). Directory `build` merges only these files.',
            'Entry file is chosen via --entry (default src/main.php relative to the project directory).',
            'Unresolved = FQCNs that could not be resolved to a file and are not known PHP classes/interfaces/traits/enums/functions/constants.',
        ];

        return new CompilationPlan(
            $entrypoints,
            $orderedFiles,
            $classmapFiles,
            $reachableFiles,
            $pruned,
            $reach->unresolvedClassReferences,
            $notes,
        );
    }
}
