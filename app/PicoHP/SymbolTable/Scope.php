<?php

namespace App\PicoHP\SymbolTable;

use Illuminate\Support\Arr;

class Scope
{
    /**
     * @var array<string, Symbol>
     */
    protected array $symbols = [];

    protected bool $global;

    public function __construct(bool $global = false)
    {
        $this->global = $global;
    }

    public function add(Symbol $s): Symbol
    {
        if (Arr::exists($this->symbols, $s->name)) {
            throw new \Exception("symbol already exists in this scope");
        }
        $this->symbols[$s->name] = $s;
        return $s;
    }

    public function lookup(string $name): ?Symbol
    {
        if (!Arr::exists($this->symbols, $name)) {
            return null;
        }
        return $this->symbols[$name];
    }
}
