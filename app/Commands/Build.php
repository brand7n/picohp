<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use PhpParser\ParserFactory;

class Build extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'build {filename}';

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

        $stmts = $parser->parse($code);
        assert(!is_null($stmts));

        $buildPath = config('app.build_path');
        assert(is_string($buildPath));
        $astOutput = "{$buildPath}/ast.json";
        $llvmIRoutput = "{$buildPath}/out.ll";

        // for debugging
        file_put_contents($astOutput, json_encode($stmts, JSON_PRETTY_PRINT));

        $symbolTable = new \App\PicoHP\SymbolTable();
        $symbolTable->resolveStmts($stmts);

        $pass = new \App\PicoHP\Pass\IRGenerationPass();
        $pass->resolveStmts($stmts);

        // for debugging
        $astWithSymbolOutput = "{$buildPath}/ast_sym.json";
        file_put_contents($astWithSymbolOutput, json_encode($stmts, JSON_PRETTY_PRINT));

        // to test with llvm
        $f = fopen($llvmIRoutput, 'w');
        assert($f !== false);
        $pass->module->print($f);

        $optimizedIR = "{$buildPath}/optimized.ll";
        $exe = "{$buildPath}/a.out";

        $llvmPath = config('app.llvm_path');
        assert(is_string($llvmPath));
        $llvmPath .= "/";
        $result = 0;
        exec("{$llvmPath}/opt -Os -S -o {$optimizedIR} {$llvmIRoutput}", result_code: $result);
        assert($result === 0);

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
