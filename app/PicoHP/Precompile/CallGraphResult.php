<?php

declare(strict_types=1);

namespace App\PicoHP\Precompile;

/**
 * Method-level call graph edges and class/function → file mappings.
 *
 * Nodes are (class, method) pairs. The special class `__global__` is used for
 * top-level functions. The special method `__class__` means "the class itself
 * is referenced" (type hint, extends, implements, new). `__main__` is top-level code.
 * `__construct` is the constructor.
 */
final class CallGraphResult
{
    /**
     * @var array<string, array<string, list<array{string, string}>>>
     *   edges[ownerClass][ownerMethod] = [(targetClass, targetMethod), ...]
     */
    public array $edges = [];

    /** @var array<string, string> FQCN => file path */
    public array $classFiles = [];

    /** @var array<string, string> function name => file path */
    public array $functionFiles = [];

    /** @var array<string, list<string>> FQCN => list of declared method names */
    public array $classMethods = [];

    public function addEdge(string $fromClass, string $fromMethod, string $toClass, string $toMethod): void
    {
        $this->edges[$fromClass][$fromMethod][] = [$toClass, $toMethod];
    }

    public function addClassFile(string $fqcn, string $file): void
    {
        $this->classFiles[$fqcn] = $file;
    }

    public function addClassMethod(string $fqcn, string $methodName): void
    {
        $this->classMethods[$fqcn][] = $methodName;
    }

    public function addFunctionFile(string $funcName, string $file): void
    {
        $this->functionFiles[$funcName] = $file;
    }

    /**
     * BFS from the given roots to find all reachable (class, method) pairs.
     *
     * @param list<array{string, string}> $roots Starting (class, method) pairs
     *
     * @return array<string, array<string, true>> reachable[class][method] = true
     */
    public function reachableFrom(array $roots): array
    {
        $visited = [];
        $queue = $roots;

        while ($queue !== []) {
            [$cls, $method] = array_shift($queue);
            if (isset($visited[$cls][$method])) {
                continue;
            }
            $visited[$cls][$method] = true;

            // Follow edges
            foreach ($this->edges[$cls][$method] ?? [] as [$targetCls, $targetMethod]) {
                if (!isset($visited[$targetCls][$targetMethod])) {
                    $queue[] = [$targetCls, $targetMethod];
                }
            }

            // If a class is reachable, its __class__ pseudo-method is too (type registration)
            if ($method !== '__class__' && !isset($visited[$cls]['__class__'])) {
                $queue[] = [$cls, '__class__'];
            }

            // When a class is instantiated (__construct or __class__), all its declared methods
            // become potentially reachable (any caller with a variable of this type can call them).
            // Without full type inference on variables, this is the safe conservative choice.
            if ($method === '__class__' || $method === '__construct') {
                foreach ($this->classMethods[$cls] ?? [] as $declaredMethod) {
                    if (!isset($visited[$cls][$declaredMethod])) {
                        $queue[] = [$cls, $declaredMethod];
                    }
                }
            }
        }

        return $visited;
    }

    /**
     * Merge another result into this one (for multi-file builds).
     */
    public function merge(CallGraphResult $other): void
    {
        foreach ($other->edges as $cls => $methods) {
            foreach ($methods as $method => $targets) {
                foreach ($targets as $target) {
                    $this->edges[$cls][$method][] = $target;
                }
            }
        }
        foreach ($other->classFiles as $fqcn => $file) {
            $this->classFiles[$fqcn] = $file;
        }
        foreach ($other->functionFiles as $name => $file) {
            $this->functionFiles[$name] = $file;
        }
        foreach ($other->classMethods as $cls => $methods) {
            foreach ($methods as $m) {
                $this->classMethods[$cls][] = $m;
            }
        }
    }

    /**
     * Given the reachable set, determine which methods on a class should be compiled.
     *
     * @param array<string, array<string, true>> $reachable
     * @return array<string, true> method names that are reachable for the given class
     */
    public function reachableMethodsForClass(string $fqcn, array $reachable): array
    {
        return $reachable[$fqcn] ?? [];
    }

    /**
     * Given the reachable set, determine which files contain reachable code.
     *
     * @param array<string, array<string, true>> $reachable
     * @return list<string> file paths
     */
    public function reachableFiles(array $reachable): array
    {
        $files = [];
        foreach ($reachable as $cls => $methods) {
            if (isset($this->classFiles[$cls])) {
                $files[$this->classFiles[$cls]] = true;
            }
            if ($cls === '__global__') {
                foreach ($methods as $funcName => $_) {
                    if (isset($this->functionFiles[$funcName])) {
                        $files[$this->functionFiles[$funcName]] = true;
                    }
                }
            }
        }
        $result = array_keys($files);
        sort($result);
        return $result;
    }
}
