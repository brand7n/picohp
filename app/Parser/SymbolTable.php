<?php

namespace App\Parser;

use Illuminate\Support\Arr;

class SymbolTable
{
    /**
     * @var array<Scope>
     */
    private array $scopes = [];


    public function __construct()
    {
        $this->enterScope(); // global scope
    }

    /**
     * Enter a new scope.
     */
    public function enterScope(): void
    {
        $this->scopes[] = new Scope();
    }

    /**
     * Exit the current scope and remove all symbols in that scope.
     */
    public function exitScope(): void
    {
        if (array_pop($this->scopes) === null) {
            throw new \Exception("scope stack empty");
        }
    }

    /**
     * Add a symbol to the symbol table.
     */
    public function addSymbol(string $name, string $type, mixed $value = null): void
    {
        $this->getCurrentScope()->add(new Symbol($name, $type, $value));
    }

    /**
     * Lookup a symbol by name in the current or outer scopes.
     */
    public function lookup(string $name): ?Symbol
    {
        foreach (array_reverse($this->scopes) as $scope) {
            $result = $scope->lookup($name);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Lookup a symbol in the current scope only.
     */
    public function lookupCurrentScope(string $name): ?Symbol
    {
        return $this->getCurrentScope()->lookup($name);
    }

    /**
     * Print the symbol table for debugging purposes.
     */
    public function __toString(): string
    {
        return var_export($this, true);
    }

    protected function getCurrentScope(): Scope
    {
        $scope = Arr::last($this->scopes);
        if ($scope === null) {
            throw new \Exception("scope stack empty");
        }
        return $scope;
    }

}