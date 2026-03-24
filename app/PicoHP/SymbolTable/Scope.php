<?php

namespace App\PicoHP\SymbolTable;

class Scope
{
    /**
     * @var array<string, Symbol>
     */
    public array $symbols = [];

    public bool $global;

    public function __construct(bool $global = false)
    {
        $this->global = $global;
    }

    public function add(Symbol $s): Symbol
    {
        if (array_key_exists($s->name, $this->symbols)) {
            throw new \Exception("symbol already exists in this scope");
        }
        $this->symbols[$s->name] = $s;
        //dump($this->symbols);
        return $s;
    }

    public function lookup(string $name): ?Symbol
    {
        if (!array_key_exists($name, $this->symbols)) {
            return null;
        }
        return $this->symbols[$name];
    }
}
