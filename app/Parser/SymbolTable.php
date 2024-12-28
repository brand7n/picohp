<?php

namespace App\Parser;

use Illuminate\Support\Arr;

class SymbolTable
{
    /**
     * @var array<string, array<Symbol>>
     */
    private array $table = [];
    private int $currentScopeLevel = 0;

    /**
     * Enter a new scope.
     */
    public function enterScope(): void
    {
        $this->currentScopeLevel++;
    }

    /**
     * Exit the current scope and remove all symbols in that scope.
     */
    public function exitScope(): void
    {
        foreach ($this->table as $name => $symbols) {
            $this->table[$name] = array_filter(
                $symbols,
                fn (Symbol $symbol) => $symbol->scopeLevel < $this->currentScopeLevel
            );
            if (count($this->table[$name]) === 0) {
                unset($this->table[$name]);
            }
        }
        $this->currentScopeLevel--;
    }

    /**
     * Add a symbol to the symbol table.
     */
    public function addSymbol(string $name, string $type, mixed $value = null): void
    {
        if (!isset($this->table[$name])) {
            $this->table[$name] = [];
        }
        $this->table[$name][] = new Symbol($name, $type, $value, $this->currentScopeLevel);
    }

    /**
     * Lookup a symbol by name in the current or outer scopes.
     */
    public function lookup(string $name): ?Symbol
    {
        if (isset($this->table[$name])) {
            return Arr::last($this->table[$name]); // Return the most recent symbol.
        }
        return null;
    }

    /**
     * Lookup a symbol in the current scope only.
     */
    public function lookupCurrentScope(string $name): ?Symbol
    {
        if (isset($this->table[$name])) {
            foreach (array_reverse($this->table[$name]) as $symbol) {
                if ($symbol->scopeLevel === $this->currentScopeLevel) {
                    return $symbol;
                }
            }
        }
        return null;
    }

    /**
     * Print the symbol table for debugging purposes.
     */
    public function __toString(): string
    {
        $output = "Symbol Table (current scope level: {$this->currentScopeLevel}):\n";
        foreach ($this->table as $name => $symbols) {
            foreach ($symbols as $symbol) {
                $output .= "  $symbol\n";
            }
        }
        return $output;
    }
}
