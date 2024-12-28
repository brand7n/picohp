<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use App\Parser\SymbolTable;

/*
TODO:
- use stack.ll to implement expression parsing?
- different runtimes in rust, 8-bit micro, hosted OS, etc
- integrate phpstan, extension for narrowing language?
- can we use type info from phpstan or some other lib?
- create a obj we can add to the AST as an attribute which contains:
  - emitter reference?
  - symbol table entry?
  - abstraction layer between AST Node from parser and LLVM/generic emitter layer
*/

it('parses a PHP program', function () {
    $result = 0;
    $parser = (new ParserFactory())->createForNewestSupportedVersion();

    $code = <<<'CODE'
    <?php

    function main(int $args): int {
        $a = 5 + 4 * 3;
        return $a;
    }

    function test1(): void {}
    CODE;

    $stmts = $parser->parse($code);

    if (is_null($stmts)) {
        return;
    }
    foreach ($stmts as $stmt) {
        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            echo($stmt->name->toString() . PHP_EOL);
        }
    }

    // exec('clang out.ll -o test', result_code: $result);
    expect($result)->toBe(0);
});

it('can store symbols in the symbol table', function () {
    $result = 0;
    // Example Usage
    $symbolTable = new SymbolTable();

    $symbolTable->addSymbol("x", "int", 42);
    $symbolTable->enterScope();
    $symbolTable->addSymbol("y", "float", 3.14);
    echo $symbolTable;

    $symbolTable->exitScope();
    echo $symbolTable;
    expect($result)->toBe(0);
});