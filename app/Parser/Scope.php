<?php

namespace App\Parser;

use Illuminate\Support\Arr;

class Scope {
    /**
     * @var array<string, Symbol>
     */
    protected array $symbols = [];

    public function add(Symbol $s): void
    {
        if (Arr::exists($this->symbols, $s->name)) {
            throw new \Exception("symbol already exists in this scope");
        }
        $this->symbols[$s->name] = $s;
    }

    public function lookup(string $name): ?Symbol
    {
        if (!Arr::exists($this->symbols, $name)) {
            return null;
        }
        return $this->symbols[$name];
    }
}