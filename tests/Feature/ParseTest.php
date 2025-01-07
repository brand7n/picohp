<?php

declare(strict_types=1);

use PhpParser\ParserFactory;

/*
TODO:
- different runtimes in rust, 8-bit micro, hosted OS, etc
- integrate phpstan, extension for narrowing language?
- can we use type info from phpstan or some other lib?
- create a obj we can add to the AST as an attribute which contains:
  - emitter reference?
  - symbol table entry?
  - abstraction layer between AST Node from parser and LLVM/generic emitter layer
- parse class properties, methods
*/

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

    if (is_null($stmts)) {
        throw new \Exception("stmts is null");
    }

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

    // for debugging
    file_put_contents('parsed.json', json_encode($stmts, JSON_PRETTY_PRINT));

    $pass = new \App\PicoHP\Pass\IRGenerationPass();
    $pass->resolveStmts($stmts);

    $code = $pass->module->getBuilder()->getLines();
    expect($code[34])->toBe('    ret i32 %or_result13');

    // to test with llvm
    $f = fopen('out.ll', 'w');
    if ($f !== false) {
        $pass->module->print($f);
    }
});
