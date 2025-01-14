<?php

namespace App\PicoHP\SymbolTable;

use App\PicoHP\LLVM\ValueAbstract;

class Symbol
{
    public string $name;
    public string $type;
    public ?ValueAbstract $value;

    public function __construct(string $name, string $type, ?ValueAbstract $value = null)
    {
        $this->name = $name;
        $this->type = $type;
        $this->value = $value;
    }

    public function __toString(): string
    {
        return sprintf(
            "Symbol(name: %s, type: %s, value: %s)",
            $this->name,
            $this->type,
            var_export($this->value, true),
        );
    }
}
