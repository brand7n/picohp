<?php

namespace App\PicoHP;

use Illuminate\Support\Arr;
use App\PicoHP\SymbolTable\{Scope, Symbol};

class SymbolTable
{
    /**
     * @var array<Scope>
     */
    protected array $scopes = [];

    public function __construct()
    {
        // push global scope on the stack
        $this->scopes[] = new Scope(true);
    }

    /**
     * Enter a new scope.
     */
    public function enterScope(): Scope
    {
        $this->scopes[] = new Scope();
        return $this->getCurrentScope();
    }

    /**
     * Exit the current scope and remove all symbols in that scope.
     */
    public function exitScope(): void
    {
        assert(count($this->scopes) > 1);
        array_pop($this->scopes);
    }

    /**
     * Add a symbol to the symbol table.
     */
    public function addSymbol(string $name, string $type, bool $func = false): Symbol
    {
        return $this->getCurrentScope()->add(new Symbol($name, $type, func: $func));
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

    public function getCurrentScope(): Scope
    {
        $scope = Arr::last($this->scopes);
        assert($scope !== null);
        return $scope;
    }
}
