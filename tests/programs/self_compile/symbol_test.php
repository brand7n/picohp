<?php

declare(strict_types=1);

namespace App\PicoHP\SymbolTable;

class PicoType
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

class Symbol
{
    public string $name;
    public PicoType $type;
    public bool $func;

    public function __construct(string $name, PicoType $type, bool $func = false)
    {
        $this->name = $name;
        $this->type = $type;
        $this->func = $func;
    }
}

$intType = new PicoType('int');
$sym = new Symbol('count', $intType, true);

echo $sym->name;
echo "\n";
echo $sym->type->name;
echo "\n";
echo $sym->func;
echo "\n";

$varSym = new Symbol('x', new PicoType('string'), false);
echo $varSym->name;
echo "\n";
echo $varSym->type->name;
echo "\n";
echo $varSym->func;
echo "\n";
