<?php

declare(strict_types=1);

use PhpParser\ParserFactory;

it('calls a picoHP lib from PHP', function () {
    $parser = (new ParserFactory())->createForNewestSupportedVersion();

    $code = <<<'CODE'
    <?php

    function ffitest(): int {
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

    if (is_null($stmts)) {
        throw new \Exception("stmts is null");
    }

    $symbolTable = new \App\PicoHP\SymbolTable();
    $symbolTable->resolveStmts($stmts);

    $pass = new \App\PicoHP\Pass\IRGenerationPass();
    $pass->resolveStmts($stmts);

    $f = fopen('out.ll', 'w');
    if ($f !== false) {
        $pass->module->print($f);
    } else {
        throw \Exception("unable to write output");
    }

    $result = 0;
    // TODO: write output to storage dir?
    exec('clang -shared -undefined dynamic_lookup -o ffitest.so out.ll', result_code: $result);
    expect($result)->toBe(0);

    $ffi = FFI::cdef("int ffitest();", "./ffitest.so");
    expect($ffi->ffitest())->toBe(49);
});
