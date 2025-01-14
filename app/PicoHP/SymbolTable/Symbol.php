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

    /** @param array<string> $params */
    public function __construct(string $name, string $type, array $params = [], ?ValueAbstract $value = null)
    {
        $this->name = $name;
        $this->type = $type;
        $this->value = $value;
        $this->params = $params;
    }

    public function isFunction(): bool
    {
        return count($this->params) > 0;
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
