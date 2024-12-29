<?php

declare(strict_types=1);

use App\PicoHP\SymbolTable;

it('can store symbols in the symbol table', function () {
    $symbolTable = new SymbolTable();

    $symbolTable->addSymbol("x", "int");
    $symbolTable->enterScope();
    $symbolTable->addSymbol("x", "float");
    $s = $symbolTable->lookup("x");
    if (is_null($s)) {
        throw new \Exception("symbol is null");
    }
    expect($s->type)->toBe("float");

    $symbolTable->exitScope();
    $s = $symbolTable->lookup("x");
    if (is_null($s)) {
        throw new \Exception("symbol is null");
    }
    expect($s->type)->toBe("int");
});
