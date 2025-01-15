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

class Build extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'build {filename} {--out=a.out} {--with-opt-ll=off} {--debug}';

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
        $astOutput = "{$buildPath}/ast.json";
        $transformedCode = "{$buildPath}/transformed_code.php";
        $llvmIRoutput = "{$buildPath}/out.ll";

        exec("rm -f {$buildPath}/*");

        $debug = $this->option('debug') === true;
        if ($debug) {
            file_put_contents($astOutput, json_encode($ast, JSON_PRETTY_PRINT));
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ClassToFunctionVisitor());
        $transformedAst = $traverser->traverse($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new GlobalToMainVisitor());
        $transformedAst = $traverser->traverse($transformedAst);

        if ($debug) {
            $prettyPrinter = new Standard();
            file_put_contents($transformedCode, $prettyPrinter->prettyPrintFile($transformedAst));
        }

        $symbolTable = new \App\PicoHP\SymbolTable();
        $symbolTable->resolveStmts($transformedAst);

        if ($debug) {
            $astWithSymbolOutput = "{$buildPath}/ast_sym.json";
            file_put_contents($astWithSymbolOutput, json_encode($transformedAst, JSON_PRETTY_PRINT));
        }

        $pass = new \App\PicoHP\Pass\IRGenerationPass($transformedAst);
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
            $optParam = is_string($this->option('with-opt-ll')) ? $this->option('with-opt-ll') : 's';
            $optimizedIR = "{$buildPath}/optimized.ll";
            exec("{$llvmPath}/opt -O{$optParam} -S -o {$optimizedIR} {$llvmIRoutput}", result_code: $result);
            assert($result === 0);
        }

        exec("{$llvmPath}/clang -o {$exe} {$optimizedIR}", result_code: $result);
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
