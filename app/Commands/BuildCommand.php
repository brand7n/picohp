<?php

declare(strict_types=1);

namespace App\Commands;

use App\PicoHP\ClassToFunctionVisitor;
use App\PicoHP\CompilerInvariantException;
use App\PicoHP\GlobalToMainVisitor;
use App\PicoHP\HandLexer\HandLexerAdapter;
use App\PicoHP\Pass\{IRGenerationPass, SemanticAnalysisPass};
use App\PicoHP\Precompile\CompilationPlan;
use App\PicoHP\Precompile\CompilationPlanner;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('build')
            ->setDescription('Build a picoHP file')
            ->addArgument('filename', InputArgument::REQUIRED, 'PHP file or project directory')
            ->addOption('out', null, InputOption::VALUE_OPTIONAL, 'Output binary name', 'a.out')
            ->addOption('with-opt-ll', null, InputOption::VALUE_OPTIONAL, 'LLVM opt level (or "off")', 'off')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Emit AST, IR, transformed PHP artifacts')
            ->addOption('shared-lib', null, InputOption::VALUE_NONE, 'Link as shared library')
            ->addOption('precompile-plan', null, InputOption::VALUE_NONE, 'Print precompile plan for directory builds and exit')
            ->addOption(
                'entry',
                null,
                InputOption::VALUE_OPTIONAL,
                'When building a project directory: path to the entry PHP file (relative to that directory, or absolute). Default: src/main.php',
                'src/main.php',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $parser = new \PhpParser\Parser\Php8(new HandLexerAdapter(), PhpVersion::getNewestSupported());
        $filename = $input->getArgument('filename');
        \App\PicoHP\CompilerInvariant::check(is_string($filename));

        $ast = [];

        if ($input->getOption('precompile-plan') === true && is_dir($filename)) {
            $entryFile = $this->resolveDirectoryEntryFile($filename, $this->entryOptionString($input));
            $planner = new CompilationPlanner();
            $plan = $planner->planDirectoryBuild($filename, $entryFile);
            $this->printPrecompilePlan($io, $plan);

            if ($input->getOption('debug') === true) {
                $buildPath = config('app.build_path');
                \App\PicoHP\CompilerInvariant::check(is_string($buildPath));
                if (!is_dir($buildPath)) {
                    mkdir($buildPath, 0700, true);
                }
                $jsonPath = "{$buildPath}/precompile_plan.json";
                file_put_contents($jsonPath, json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $io->writeln("Wrote {$jsonPath}");
            }

            return Command::SUCCESS;
        }

        try {
            if (is_dir($filename)) {
                $entryFile = $this->resolveDirectoryEntryFile($filename, $this->entryOptionString($input));
                $ast = $this->walkClassMap($filename, $entryFile);
            } else {
                if (!file_exists($filename)) {
                    $io->error("Unable to open input file: {$filename}");

                    return Command::FAILURE;
                }
                $code = file_get_contents($filename);
                // @codeCoverageIgnoreStart
                if ($code === false) {
                    $io->error("Unable to read input file: {$filename}");

                    return Command::FAILURE;
                }
                // @codeCoverageIgnoreEnd

                $ast = $parser->parse($code);
                // @codeCoverageIgnoreStart
                if (is_null($ast)) {
                    $io->error("Failed to parse input file: {$filename}");

                    return Command::FAILURE;
                }
                // @codeCoverageIgnoreEnd
            }
        } catch (\PhpParser\Error $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
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

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(options: ['replaceNodes' => false]));
        $ast = $traverser->traverse($ast);

        $debug = $input->getOption('debug') === true;
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

        try {
            $semanticPass = new SemanticAnalysisPass($transformedAst);
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

                return Command::FAILURE;
            }
            // @codeCoverageIgnoreEnd
            $pass->module->print($f);

            $outfile = $input->getOption('out');
            \App\PicoHP\CompilerInvariant::check(is_string($outfile));
            $exe = "{$buildPath}/{$outfile}";

            $llvmPath = config('app.llvm_path');
            \App\PicoHP\CompilerInvariant::check(is_string($llvmPath));
            $llvmPath .= '/';
            $result = 0;

            if ($input->getOption('with-opt-ll') === 'off') {
                $optimizedIR = $llvmIRoutput;
            } else {
                // @codeCoverageIgnoreStart
                $optParam = is_string($input->getOption('with-opt-ll')) ? $input->getOption('with-opt-ll') : 's';
                $optimizedIR = "{$buildPath}/optimized.ll";
                exec("{$llvmPath}/opt -O{$optParam} -S -o {$optimizedIR} {$llvmIRoutput}", result_code: $result);
                if ($result !== 0) {
                    $io->error("opt failed with exit code {$result}");

                    return Command::FAILURE;
                }
                // @codeCoverageIgnoreEnd
            }

            $sharedLibOpts = '';
            if ($input->getOption('shared-lib') === true || !$globalToMain->hasMain) {
                $sharedLibOpts = '-shared -undefined dynamic_lookup';
            }
            $runtimePath = config('app.runtime_path');
            \App\PicoHP\CompilerInvariant::check(is_string($runtimePath));
            $runtimeLink = "-L{$runtimePath} -lpico_rt -Wl,-rpath,{$runtimePath}";
            exec("{$llvmPath}/clang -Wno-override-module {$sharedLibOpts} {$runtimeLink} -o {$exe} {$optimizedIR}", result_code: $result);
            // @codeCoverageIgnoreStart
            if ($result !== 0) {
                $io->error("clang failed with exit code {$result}");

                return Command::FAILURE;
            }
            // @codeCoverageIgnoreEnd
        } catch (CompilerInvariantException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<\PhpParser\Node>
     */
    protected function walkClassMap(string $path, string $entryFileAbsolute): array
    {
        /** @var array<string, string> */
        $classMap = require $path . '/vendor/composer/autoload_classmap.php';

        $main = realpath($entryFileAbsolute);
        if ($main === false || !is_file($main)) {
            throw new \RuntimeException("Entry point not found: {$entryFileAbsolute}");
        }

        $nodes = $this->getNodes($main);
        foreach ($classMap as $class => $file) {
            if ($class !== 'Composer\InstalledVersions') {
                $nodes = array_merge($nodes, $this->getNodes($file));
            }
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
        return $ast;
    }

    private function entryOptionString(InputInterface $input): string
    {
        $entry = $input->getOption('entry');
        \App\PicoHP\CompilerInvariant::check($entry === null || is_string($entry));

        return is_string($entry) ? $entry : 'src/main.php';
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

    private function printPrecompilePlan(SymfonyStyle $io, CompilationPlan $plan): void
    {
        $entryCount = count($plan->entrypoints);
        $io->writeln("Precompile plan ({$entryCount} entrypoint" . ($entryCount === 1 ? '' : 's') . ')');
        foreach ($plan->entrypoints as $p) {
            $io->writeln('  entry: ' . $p);
        }
        $io->newLine();
        $io->writeln('Compile order (' . count($plan->orderedFiles) . ' files):');
        $i = 1;
        foreach ($plan->orderedFiles as $file) {
            $io->writeln(sprintf('  %3d. %s', $i, $file));
            $i++;
        }
        $io->newLine();
        $io->writeln('Reachable from entrypoints (' . count($plan->reachableFiles) . ' files, stub):');
        foreach ($plan->reachableFiles as $file) {
            $io->writeln('  - ' . $file);
        }
        $io->newLine();
        $io->writeln('Pruned — classmap files not reachable from entrypoints (' . count($plan->prunedFiles) . ' files):');
        foreach ($plan->prunedFiles as $file) {
            $io->writeln('  - ' . $file);
        }
        if ($plan->prunedFiles === []) {
            $io->writeln('  (none)');
        }
        $io->newLine();
        $io->writeln('Unresolved references (not a loadable class file and not a known PHP builtin) (' . count($plan->unresolvedClassReferences) . '):');
        if ($plan->unresolvedClassReferences === []) {
            $io->writeln('  (none)');
        } else {
            foreach (array_slice($plan->unresolvedClassReferences, 0, 50) as $ref) {
                $io->writeln('  - ' . $ref);
            }
            if (count($plan->unresolvedClassReferences) > 50) {
                $io->writeln('  … (' . (count($plan->unresolvedClassReferences) - 50) . ' more)');
            }
        }
        $io->newLine();
        $io->writeln('Notes:');
        foreach ($plan->notes as $note) {
            $io->writeln('  - ' . $note);
        }
    }
}
