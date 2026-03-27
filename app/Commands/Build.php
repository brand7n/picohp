<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard;
use App\PicoHP\ClassToFunctionVisitor;
use App\PicoHP\CompilerInvariantException;
use App\PicoHP\GlobalToMainVisitor;
use App\PicoHP\Pass\{IRGenerationPass, SemanticAnalysisPass};
use App\PicoHP\HandLexer\HandLexerAdapter;

class Build extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'build {filename} {--out=a.out} {--with-opt-ll=off} {--debug} {--shared-lib}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build a picoHP file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $parser = new \PhpParser\Parser\Php8(new HandLexerAdapter(), PhpVersion::getNewestSupported());
        $filename = $this->argument('filename');

        $ast = [];
        \App\PicoHP\CompilerInvariant::check(is_string($filename));
        try {
            if (is_dir($filename)) {
                $ast = $this->walkClassMap($filename);
            } else {
                if (!file_exists($filename)) {
                    $this->error("Unable to open input file: {$filename}");
                    return 1;
                }
                $code = file_get_contents($filename);
                // @codeCoverageIgnoreStart
                if ($code === false) {
                    $this->error("Unable to read input file: {$filename}");
                    return 1;
                }
                // @codeCoverageIgnoreEnd

                $ast = $parser->parse($code);
                // @codeCoverageIgnoreStart
                if (is_null($ast)) {
                    $this->error("Failed to parse input file: {$filename}");
                    return 1;
                }
                // @codeCoverageIgnoreEnd
            }
        } catch (\PhpParser\Error $e) {
            $this->error($e->getMessage());
            return 1;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
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

        // TODO: add static analysis pass (psalm, phpstan, etc)

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(options: ['replaceNodes' => false]));
        $ast = $traverser->traverse($ast);

        $debug = $this->option('debug') === true;
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

        // TODO: transform exceptions?

        if ($debug) {
            $prettyPrinter = new Standard();
            file_put_contents($transformedCode, $prettyPrinter->prettyPrintFile($transformedAst));

            // TODO: rerun static analysis on transformed output?
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
                $this->error("Unable to open output file: {$llvmIRoutput}");

                return 1;
            }
            // @codeCoverageIgnoreEnd
            $pass->module->print($f);

            $outfile = $this->option('out');
            \App\PicoHP\CompilerInvariant::check(is_string($outfile));
            $exe = "{$buildPath}/{$outfile}";

            $llvmPath = config('app.llvm_path');
            \App\PicoHP\CompilerInvariant::check(is_string($llvmPath));
            $llvmPath .= '/';
            $result = 0;

            if ($this->option('with-opt-ll') === 'off') {
                $optimizedIR = $llvmIRoutput;
            } else {
                // @codeCoverageIgnoreStart
                $optParam = is_string($this->option('with-opt-ll')) ? $this->option('with-opt-ll') : 's';
                $optimizedIR = "{$buildPath}/optimized.ll";
                exec("{$llvmPath}/opt -O{$optParam} -S -o {$optimizedIR} {$llvmIRoutput}", result_code: $result);
                if ($result !== 0) {
                    $this->error("opt failed with exit code {$result}");

                    return 1;
                }
                // @codeCoverageIgnoreEnd
            }

            $sharedLibOpts = '';
            if ($this->option('shared-lib') === true || !$globalToMain->hasMain) {
                $sharedLibOpts = '-shared -undefined dynamic_lookup';
            }
            $runtimePath = config('app.runtime_path');
            \App\PicoHP\CompilerInvariant::check(is_string($runtimePath));
            $runtimeLink = "-L{$runtimePath} -lpico_rt -Wl,-rpath,{$runtimePath}";
            exec("{$llvmPath}/clang -Wno-override-module {$sharedLibOpts} {$runtimeLink} -o {$exe} {$optimizedIR}", result_code: $result);
            // @codeCoverageIgnoreStart
            if ($result !== 0) {
                $this->error("clang failed with exit code {$result}");

                return 1;
            }
            // @codeCoverageIgnoreEnd
        } catch (CompilerInvariantException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    /**
     * @return array<\PhpParser\Node>
     */
    protected function walkClassMap(string $path): array
    {
        /** @var array<string, string> */
        $classMap = require $path . '/vendor/composer/autoload_classmap.php';

        // for now assume src/main.php is our entry point
        $main = realpath($path . '/src/main.php');
        if (!is_string($main)) {
            throw new \RuntimeException("Entry point not found: {$path}/src/main.php");
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
}
