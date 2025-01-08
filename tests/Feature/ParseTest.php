<?php

declare(strict_types=1);

use PhpParser\ParserFactory;

it('parses a PHP program', function () {
    $parser = (new ParserFactory())->createForNewestSupportedVersion();

    $code = <<<'CODE'
    <?php

    function main(): int {
        /** @var int */
        $a = 4;
        /** @var int */
        $b = 5;
        /** @var int */
        $c = 64;
        /** @var int */
        $d = 32;
        return ($b + ($a * 3)) | ($d & ($c / 2));
    }

    CODE;

    $stmts = $parser->parse($code);
    assert(!is_null($stmts));

    $names = [];
    foreach ($stmts as $stmt) {
        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            $names[] = $stmt->name->toString();
        }
    }

    expect(count($names))->toBe(1);
    expect($names[0])->toBe('main');

    $symbolTable = new \App\PicoHP\SymbolTable();
    $symbolTable->resolveStmts($stmts);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $astOutput = "{$buildPath}/ast.json";
    $llvmIRoutput = "{$buildPath}/out.ll";

    // for debugging
    file_put_contents($astOutput, json_encode($stmts, JSON_PRETTY_PRINT));

    $pass = new \App\PicoHP\Pass\IRGenerationPass();
    $pass->resolveStmts($stmts);

    $code = $pass->module->getBuilder()->getLines();
    expect($code[34])->toBe('    ret i32 %or_result13');

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
    expect($result)->toBe(0);

    exec("{$llvmPath}/clang -o {$exe} {$optimizedIR}", result_code: $result);
    expect($result)->toBe(0);

    exec($exe, result_code: $result);
    expect($result)->toBe(49);
});
