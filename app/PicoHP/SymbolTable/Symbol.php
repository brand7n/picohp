<?php

declare(strict_types=1);

namespace App\PicoHP\SymbolTable;

use App\PicoHP\LLVM\ValueAbstract;
use App\PicoHP\PicoType;

class Symbol
{
    public string $name; // variable or function name
    public PicoType $type;
    /** @var array<PicoType> */
    public array $params;
    public ?ValueAbstract $value;
    public bool $func;

    /** @param array<PicoType> $params */
    public function __construct(string $name, PicoType $type, array $params = [], ?ValueAbstract $value = null, bool $func = false)
    {
        $this->name = $name;
        $this->type = $type;

        // TODO: move to pData
        $this->value = $value;

        // TODO: use type for function params
        $this->params = $params;
        $this->func = $func;
    }
}
