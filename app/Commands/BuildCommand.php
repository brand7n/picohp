<?php

declare(strict_types=1);

namespace App\Commands;

use App\Cli\BuildOptions;
use App\Cli\ConsoleIo;
use App\PicoHP\ClassToFunctionVisitor;
use App\PicoHP\GlobalToMainVisitor;
use App\PicoHP\HandLexer\HandLexerAdapter;
use App\PicoHP\Pass\{IRGenerationPass, SemanticAnalysisPass};
use App\PicoHP\Precompile\CompilationPlan;
use App\PicoHP\Precompile\CompilationPlanner;
use App\PicoHP\SourceFileAttributeVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard;

final class BuildCommand
{
    /**
     * @param list<string> $argv Full PHP argv (script name first), e.g. {@code ['picohp', 'build', '--debug', 'file.php']}
     */
    public static function runFromArgv(array $argv): int
    {
        if (count($argv) < 2) {
            fwrite(STDERR, "Usage: picohp build <file|directory> [options]\n");

            return 1;
        }
        if ($argv[1] !== 'build') {
            fwrite(STDERR, "Unknown command: {$argv[1]}\n");

            return 1;
        }
        $tokens = array_slice($argv, 2);
        try {
            $options = BuildOptions::parse($tokens);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 1;
        }
        if ($options->filename === null || $options->filename === '') {
            fwrite(STDERR, "Missing argument: PHP file or project directory\n");

            return 1;
        }
        $io = ConsoleIo::fromVerbosity($options->verbosity);

        return (new self())->run($options, $io);
    }

    public function run(BuildOptions $options, ConsoleIo $io): int
    {
        $parser = new \PhpParser\Parser\Php8(new HandLexerAdapter(), PhpVersion::getNewestSupported());
        $filename = $options->filename;
        \App\PicoHP\CompilerInvariant::check(is_string($filename));

        $ast = [];

        if ($options->precompilePlan && is_dir($filename)) {
            $entryFile = $this->resolveDirectoryEntryFile($filename, $options->entry);
            $planner = new CompilationPlanner();
            $plan = $planner->planDirectoryBuild($filename, $entryFile);
            $this->printPrecompilePlan($io, $plan);

            if ($options->debug === true) {
                $buildPath = config('app.build_path');
                \App\PicoHP\CompilerInvariant::check(is_string($buildPath));
                if (!is_dir($buildPath)) {
                    mkdir($buildPath, 0700, true);
                }
                $jsonPath = "{$buildPath}/precompile_plan.json";
                file_put_contents($jsonPath, json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $io->writeln("Wrote {$jsonPath}");
            }

            return 0;
        }

        try {
            if (is_dir($filename)) {
                $entryFile = $this->resolveDirectoryEntryFile($filename, $options->entry);
                $ast = $this->walkClassMap($filename, $entryFile, $io);
            } else {
                if (!file_exists($filename)) {
                    $io->error("Unable to open input file: {$filename}");

                    return 1;
                }
                $code = file_get_contents($filename);
                // @codeCoverageIgnoreStart
                if ($code === false) {
                    $io->error("Unable to read input file: {$filename}");

                    return 1;
                }
                // @codeCoverageIgnoreEnd

                $ast = $parser->parse($code);
                // @codeCoverageIgnoreStart
                if (is_null($ast)) {
                    $io->error("Failed to parse input file: {$filename}");

                    return 1;
                }
                // @codeCoverageIgnoreEnd
                $resolved = realpath($filename);
                $ast = $this->annotateAstWithSourceFile($ast, $resolved !== false ? $resolved : $filename);
            }
        } catch (\PhpParser\Error $e) {
            $io->error($e->getMessage());

            return 1;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $buildPath = config('app.build_path');
        \App\PicoHP\CompilerInvariant::check(is_string($buildPath));
        if (!is_dir($buildPath)) {
            // @codeCoverageIgnoreStart
            mkdir($buildPath, 0700, true);
            // @codeCoverageIgnoreEnd
        } else {
            exec("rm -f {$buildPath}/*");
        }
        $astOutput = "{$buildPath}/ast.json";
        $transformedCode = "{$buildPath}/transformed_code.php";
        $llvmIRoutput = "{$buildPath}/out.ll";

        $debug = $options->debug === true;

        try {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(options: ['replaceNodes' => false]));
            $ast = $traverser->traverse($ast);

            if ($debug) {
                file_put_contents($astOutput, json_encode($ast, JSON_PRETTY_PRINT));
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ClassToFunctionVisitor());
            $transformedAst = $traverser->traverse($ast);

            $traverser = new NodeTraverser();
            $globalToMain = new GlobalToMainVisitor();
            $traverser->addVisitor($globalToMain);
            $transformedAst = $traverser->traverse($transformedAst);

            if ($debug) {
                $prettyPrinter = new Standard();
                file_put_contents($transformedCode, $prettyPrinter->prettyPrintFile($transformedAst));
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            if ($debug) {
                $io->writeln($e->getTraceAsString());
            } else {
                $io->note('Run with --debug (-d) to write ast.json after name resolution (and more if the pipeline progresses further).');
            }

            return 1;
        }

        try {
            $semanticPass = new SemanticAnalysisPass($transformedAst, static function (string $message) use ($io): void {
                $io->warning($message);
            });
            $semanticPass->exec();

            if ($debug) {
                $astWithSymbolOutput = "{$buildPath}/ast_sym.json";
                file_put_contents($astWithSymbolOutput, json_encode($transformedAst, JSON_PRETTY_PRINT));
            }

            $pass = new IRGenerationPass($transformedAst, $semanticPass->getClassRegistry(), $semanticPass->getEnumRegistry(), $semanticPass->getTypeIdMap());
            $pass->exec();

            $f = fopen($llvmIRoutput, 'w');
            // @codeCoverageIgnoreStart
            if ($f === false) {
                $io->error("Unable to open output file: {$llvmIRoutput}");

                return 1;
            }
            // @codeCoverageIgnoreEnd
            $pass->module->print($f);

            $exe = "{$buildPath}/{$options->out}";

            $llvmPath = config('app.llvm_path');
            \App\PicoHP\CompilerInvariant::check(is_string($llvmPath));
            $llvmPath .= '/';
            $result = 0;

            if ($options->withOptLl === 'off') {
                $optimizedIR = $llvmIRoutput;
            } else {
                // @codeCoverageIgnoreStart
                $optParam = $options->withOptLl !== '' ? $options->withOptLl : 's';
                $optimizedIR = "{$buildPath}/optimized.ll";
                exec("{$llvmPath}/opt -O{$optParam} -S -o {$optimizedIR} {$llvmIRoutput}", result_code: $result);
                if ($result !== 0) {
                    $io->error("opt failed with exit code {$result}");

                    return 1;
                }
                // @codeCoverageIgnoreEnd
            }

            $sharedLibOpts = '';
            if ($options->sharedLib === true || !$globalToMain->hasMain) {
                $sharedLibOpts = '-shared -undefined dynamic_lookup';
            }
            $runtimePath = config('app.runtime_path');
            \App\PicoHP\CompilerInvariant::check(is_string($runtimePath));
            $runtimeLink = "-L{$runtimePath} -lpico_rt -Wl,-rpath,{$runtimePath}";
            exec("{$llvmPath}/clang -Wno-override-module {$sharedLibOpts} {$runtimeLink} -o {$exe} {$optimizedIR}", result_code: $result);
            // @codeCoverageIgnoreStart
            if ($result !== 0) {
                $io->error("clang failed with exit code {$result}");

                return 1;
            }
            // @codeCoverageIgnoreEnd
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * Merges ASTs only for files reachable from {@code --entry} (same graph as {@see CompilationPlanner}).
     *
     * @return array<\PhpParser\Node>
     */
    protected function walkClassMap(string $path, string $entryFileAbsolute, ConsoleIo $io): array
    {
        $main = realpath($entryFileAbsolute);
        if ($main === false || !is_file($main)) {
            throw new \RuntimeException("Entry point not found: {$entryFileAbsolute}");
        }

        if ($io->isVerbose()) {
            $io->text(sprintf('<comment>Build</comment>: entry %s', $main));
        }

        $planner = new CompilationPlanner();
        $plan = $planner->planDirectoryBuild($path, $entryFileAbsolute);

        $nodes = [];
        foreach ($plan->reachableFiles as $file) {
            if ($io->isVeryVerbose()) {
                $io->writeln('  [parse] ' . $file);
            }
            $nodes = array_merge($nodes, $this->getNodes($file));
        }

        if ($io->isVerbose()) {
            $io->text(sprintf('<comment>Build</comment>: merged %d reachable file(s) into AST', count($plan->reachableFiles)));
        }

        return $nodes;
    }

    /**
     * @return array<\PhpParser\Node>
     */
    protected function getNodes(string $filename): array
    {
        $parser = new \PhpParser\Parser\Php8(new HandLexerAdapter(), PhpVersion::getNewestSupported());
        $code = file_get_contents($filename);
        // @codeCoverageIgnoreStart
        if ($code === false) {
            throw new \RuntimeException("Unable to open input file: {$filename}");
        }
        // @codeCoverageIgnoreEnd
        $ast = $parser->parse($code);
        // @codeCoverageIgnoreStart
        if (is_null($ast)) {
            throw new \RuntimeException("Failed to parse input file: {$filename}");
        }
        // @codeCoverageIgnoreEnd

        // TODO: filter out everything except classes and functions
        $resolved = realpath($filename);

        return $this->annotateAstWithSourceFile($ast, $resolved !== false ? $resolved : $filename);
    }

    /**
     * Tags every node with {@code pico_source_file} so transforms can report errors with file paths.
     *
     * @param array<\PhpParser\Node> $nodes
     * @return array<\PhpParser\Node>
     */
    private function annotateAstWithSourceFile(array $nodes, string $sourceFileAbsolute): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new SourceFileAttributeVisitor($sourceFileAbsolute));

        return $traverser->traverse($nodes);
    }

    /**
     * Resolves {@code --entry} for a directory build: relative paths are taken relative to the project directory.
     */
    private function resolveDirectoryEntryFile(string $projectRoot, string $entry): string
    {
        $entry = trim($entry);
        if ($entry === '') {
            $entry = 'src/main.php';
        }

        if ($this->isAbsoluteFilesystemPath($entry)) {
            $candidate = $entry;
        } else {
            $norm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $entry);
            $candidate = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $norm;
        }

        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            throw new \RuntimeException("Entry point not found: {$candidate}");
        }

        return $real;
    }

    private function isAbsoluteFilesystemPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (str_starts_with($path, '/')) {
            return true;
        }

        return PHP_OS_FAMILY === 'Windows' && preg_match('~^[A-Za-z]:[/\\\\]~', $path) === 1;
    }

    private function printPrecompilePlan(ConsoleIo $io, CompilationPlan $plan): void
    {
        $io->writeln('entry');
        foreach ($plan->entrypoints as $p) {
            $io->writeln('  ' . $p);
        }
        $io->newLine();
        $io->writeln('reachable');
        if ($plan->reachableFiles === []) {
            $io->writeln('  (none)');
        } else {
            foreach ($plan->reachableFiles as $file) {
                $io->writeln('  ' . $file);
            }
        }
        $io->newLine();
        $io->writeln('unresolved');
        if ($plan->unresolvedClassReferences === []) {
            $io->writeln('  (none)');
        } else {
            foreach ($plan->unresolvedClassReferences as $ref) {
                $io->writeln('  ' . $ref);
            }
        }
    }
}
