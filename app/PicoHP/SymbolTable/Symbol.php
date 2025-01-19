<?php

declare(strict_types=1);

namespace App\PicoHP\SymbolTable;

use App\PicoHP\LLVM\ValueAbstract;

class Symbol
{
    public string $name; // variable or function name
    public string $type; // e.g. "int", "string", "float", "mixed", "void"
    /** @var array<string> */
    public array $params;
    public ?ValueAbstract $value;
    public bool $func;

    /** @param array<string> $params */
    public function __construct(string $name, string $type, array $params = [], ?ValueAbstract $value = null, bool $func = false)
    {
        $this->name = $name;
        $this->type = $type;
        $this->value = $value;
        $this->params = $params;
        $this->func = $func;
    }
}
