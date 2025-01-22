<?php

declare(strict_types=1);

use App\PicoHP\SymbolTable;
use App\PicoHP\PicoType;

it('can store symbols in the symbol table', function () {
    $symbolTable = new SymbolTable();

    $symbolTable->addSymbol("x", PicoType::fromString("int"));
    $symbolTable->enterScope();
    $symbolTable->addSymbol("x", PicoType::fromString("float"));
    $s = $symbolTable->lookup("x");
    if (is_null($s)) {
        throw new \Exception("symbol is null");
    }
    expect($s->type->toString())->toBe("float");

    $symbolTable->exitScope();
    $s = $symbolTable->lookup("x");
    if (is_null($s)) {
        throw new \Exception("symbol is null");
    }
    expect($s->type->toString())->toBe("int");
});

it('doesn\'t store the same symbol twice', function () {
    $symbolTable = new SymbolTable();

    $symbolTable->addSymbol("x", PicoType::fromString("int"));
    $symbolTable->addSymbol("x", PicoType::fromString("float"));
})->throws(\Exception::class, "symbol already exists in this scope");
