<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter\Standard;
use App\PicoHP\ClassToFunctionVisitor;
use App\PicoHP\GlobalToMainVisitor;
use App\PicoHP\Pass\{IRGenerationPass, SemanticAnalysisPass};

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
     * @var string|null
     */
    protected $description = 'Build a picoHP file';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $filename = $this->argument('filename');
        assert(is_string($filename));
        $code = file_get_contents($filename);
        assert($code !== false, "Unable to open input file");

        $ast = $parser->parse($code);
        assert(!is_null($ast));

        $buildPath = config('app.build_path');
        assert(is_string($buildPath));
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

        $debug = $this->option('debug') === true;
        if ($debug) {
            file_put_contents($astOutput, json_encode($ast, JSON_PRETTY_PRINT));
        }

        // TODO: add static analysis pass (psalm, phpstan, etc)

        // TODO: add name resolver visitor?

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ClassToFunctionVisitor());
        $transformedAst = $traverser->traverse($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new GlobalToMainVisitor());
        $transformedAst = $traverser->traverse($transformedAst);

        // TODO: transform exceptions?

        if ($debug) {
            $prettyPrinter = new Standard();
            file_put_contents($transformedCode, $prettyPrinter->prettyPrintFile($transformedAst));

            // TODO: rerun static analysis on transformed output?
        }

        $pass = new SemanticAnalysisPass($transformedAst);
        $pass->exec();

        if ($debug) {
            $astWithSymbolOutput = "{$buildPath}/ast_sym.json";
            file_put_contents($astWithSymbolOutput, json_encode($transformedAst, JSON_PRETTY_PRINT));
        }

        $pass = new IRGenerationPass($transformedAst);
        $pass->exec();

        $f = fopen($llvmIRoutput, 'w');
        assert($f !== false);
        $pass->module->print($f);

        $outfile = $this->option('out');
        assert(is_string($outfile));
        $exe = "{$buildPath}/{$outfile}";

        $llvmPath = config('app.llvm_path');
        assert(is_string($llvmPath));
        $llvmPath .= "/";
        $result = 0;

        if ($this->option('with-opt-ll') === 'off') {
            $optimizedIR = $llvmIRoutput;
        } else {
            // @codeCoverageIgnoreStart
            $optParam = is_string($this->option('with-opt-ll')) ? $this->option('with-opt-ll') : 's';
            $optimizedIR = "{$buildPath}/optimized.ll";
            exec("{$llvmPath}/opt -O{$optParam} -S -o {$optimizedIR} {$llvmIRoutput}", result_code: $result);
            assert($result === 0);
            // @codeCoverageIgnoreEnd
        }

        $sharedLibOpts = '';
        if ($this->option('shared-lib') === true) {
            $sharedLibOpts = '-shared -undefined dynamic_lookup';
        }
        exec("{$llvmPath}/clang -Wno-override-module {$sharedLibOpts} -o {$exe} {$optimizedIR}", result_code: $result);
        assert($result === 0);
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
