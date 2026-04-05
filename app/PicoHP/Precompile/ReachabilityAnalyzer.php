<?php

declare(strict_types=1);

namespace App\PicoHP\Precompile;

use Composer\Autoload\ClassLoader;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\PhpVersion;

/**
 * Walks PHP sources from entrypoints: follows class references resolved via Composer's
 * {@see ClassLoader::findFile()} (classmap + PSR-4 + PSR-0), and literal {@code require}/{@code include}
 * whose argument is a single string scalar. Uses the stock php-parser lexer (not HandLexer).
 */
final class ReachabilityAnalyzer
{
    /**
     * FQCNs seen in vendor sources that {@see ClassLoader::findFile()} cannot resolve: optional
     * Symfony integration types when those packages are not installed, and PHPStan core classes
     * that live only in the phpstan.phar (not the optimized classmap).
     *
     * @var list<string>
     */
    private const KNOWN_UNMAPPED_VENDOR_CLASS_REFERENCES = [
        'PHPStan\ShouldNotHappenException',
        'Symfony\Component\ErrorHandler\ErrorHandler',
        'Symfony\Contracts\EventDispatcher\Event',
        'Symfony\Contracts\EventDispatcher\EventDispatcherInterface',
    ];

    public function __construct(
        private readonly Parser $parser,
    ) {
    }

    public static function createDefault(): self
    {
        $lexer = new \PhpParser\Lexer();
        $parser = new \PhpParser\Parser\Php8($lexer, PhpVersion::getNewestSupported());

        return new self($parser);
    }

    /**
     * @param list<string> $entrypointPaths Absolute paths
     */
    public function analyze(ComposerAutoloadGraph $graph, array $entrypointPaths, string $projectRoot): ReachabilityResult
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $autoloadPhp = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $composerLoader = is_file($autoloadPhp) ? require $autoloadPhp : null;
        if (!$composerLoader instanceof ClassLoader) {
            $composerLoader = null;
        }

        $queue = [];
        foreach ($entrypointPaths as $p) {
            $r = realpath($p);
            if ($r !== false && is_file($r)) {
                $queue[] = $r;
            }
        }

        $visited = [];
        $reachable = [];
        $unresolved = [];

        $finder = new NodeFinder();

        while ($queue !== []) {
            $file = array_shift($queue);
            if (isset($visited[$file])) {
                continue;
            }
            $visited[$file] = true;
            $reachable[] = $file;

            $code = @file_get_contents($file);
            if ($code === false) {
                continue;
            }

            try {
                $ast = $this->parser->parse($code);
            } catch (\PhpParser\Error) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $ast = $traverser->traverse($ast);

            foreach ($finder->findInstanceOf($ast, FullyQualified::class) as $nameNode) {
                $fqcn = $nameNode->toString();
                $resolvedFile = $this->resolveClassToPath($fqcn, $graph, $composerLoader);
                if ($resolvedFile !== null) {
                    $target = realpath($resolvedFile);
                    if ($target !== false && !isset($visited[$target])) {
                        $queue[] = $target;
                    }

                    continue;
                }

                if ($this->isKnownPhpSymbol($fqcn)) {
                    continue;
                }

                if ($this->isKnownUnmappedVendorClassReference($fqcn)) {
                    continue;
                }

                $unresolved[$fqcn] = true;
            }

            foreach ($finder->findInstanceOf($ast, Include_::class) as $includeNode) {
                $expr = $includeNode->expr;
                if (!$expr instanceof String_) {
                    continue;
                }
                $relative = $expr->value;
                $resolved = realpath(\dirname($file) . DIRECTORY_SEPARATOR . $relative);
                if ($resolved === false || !is_file($resolved) || !str_ends_with($resolved, '.php')) {
                    continue;
                }
                if (!isset($visited[$resolved])) {
                    $queue[] = $resolved;
                }
            }
        }

        sort($reachable);
        $unresolvedList = array_keys($unresolved);
        sort($unresolvedList);

        // Phase 2: build call graph over reachable files to determine reachable classes
        $callGraph = $this->buildCallGraphForFiles($reachable);
        $entryRoots = $this->determineCallGraphRoots($entrypointPaths, $callGraph);
        $reachableSet = $callGraph->reachableFrom($entryRoots);
        $reachableClasses = [];
        foreach ($reachableSet as $cls => $methods) {
            if ($cls !== '__global__') {
                $reachableClasses[$cls] = true;
            }
        }

        return new ReachabilityResult($reachable, $unresolvedList, $reachableClasses);
    }

    /**
     * Resolve a fully-qualified name to a PHP file path using Composer (preferred) or the
     * snapshot classmap (e.g. minimal temp projects in unit tests without vendor/).
     */
    private function resolveClassToPath(string $fqcn, ComposerAutoloadGraph $graph, ?ClassLoader $composerLoader): ?string
    {
        if (isset($graph->classPathOverrides[$fqcn])) {
            $p = $graph->classPathOverrides[$fqcn];
            if (is_file($p)) {
                return $p;
            }
        }

        if ($composerLoader instanceof ClassLoader) {
            $path = $composerLoader->findFile($fqcn);
            if ($path !== false && is_file($path)) {
                return $path;
            }
        }

        if (isset($graph->classmap[$fqcn])) {
            $p = $graph->classmap[$fqcn];
            if (is_file($p)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * True for PHP core classes, interfaces, traits, enums, functions, and global/namespaced constants
     * so they are not listed as "unresolved" when {@see resolveClassToPath} fails (e.g. {@code FullyQualified}
     * inside {@code ConstFetch}).
     */
    private function isKnownPhpSymbol(string $fqcn): bool
    {
        if (class_exists($fqcn, false) || interface_exists($fqcn, false) || trait_exists($fqcn, false)) {
            return true;
        }

        if (enum_exists($fqcn, false)) {
            return true;
        }

        if (function_exists($fqcn)) {
            return true;
        }

        if (\defined($fqcn)) {
            return true;
        }

        return false;
    }

    /**
     * Build a call graph over all reachable files.
     *
     * @param list<string> $files
     */
    private function buildCallGraphForFiles(array $files): CallGraphResult
    {
        $builder = new CallGraphBuilder();
        $merged = new CallGraphResult();

        foreach ($files as $file) {
            $code = @file_get_contents($file);
            if ($code === false) {
                continue;
            }
            try {
                $ast = $this->parser->parse($code);
            } catch (\PhpParser\Error) {
                continue;
            }
            if ($ast === null) {
                continue;
            }
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $ast = $traverser->traverse($ast);

            $result = $builder->extractFromAst($ast, $file);
            $merged->merge($result);
        }

        return $merged;
    }

    /**
     * Determine call graph roots from entrypoint files (top-level code + declared functions).
     *
     * @param list<string> $entrypointPaths
     * @return list<array{string, string}>
     */
    private function determineCallGraphRoots(array $entrypointPaths, CallGraphResult $callGraph): array
    {
        $roots = [['__global__', '__main__']];

        // All functions declared in entrypoint files are roots
        foreach ($callGraph->functionFiles as $funcName => $file) {
            foreach ($entrypointPaths as $entry) {
                $entryReal = realpath($entry);
                if ($entryReal !== false && $file === $entryReal) {
                    $roots[] = ['__global__', $funcName];
                }
            }
        }

        // All classes declared in entrypoint files are roots
        foreach ($callGraph->classFiles as $fqcn => $file) {
            foreach ($entrypointPaths as $entry) {
                $entryReal = realpath($entry);
                if ($entryReal !== false && $file === $entryReal) {
                    $roots[] = [$fqcn, '__class__'];
                }
            }
        }

        return $roots;
    }

    /**
     * Vendor code may reference classes that are real at runtime but not discoverable via
     * Composer's file mapping (phar-only symbols, optional peer dependencies).
     */
    private function isKnownUnmappedVendorClassReference(string $fqcn): bool
    {
        return \in_array($fqcn, self::KNOWN_UNMAPPED_VENDOR_CLASS_REFERENCES, true);
    }
}
