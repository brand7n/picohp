<?php

namespace App\PicoHP;

use Illuminate\Support\Arr;
use App\PicoHP\SymbolTable\{Scope, Symbol};

class SymbolTable
{
    /**
     * @var array<Scope>
     */
    private array $scopes = [];


    public function __construct()
    {
        // push global scope on the stack
        $this->scopes[] = new Scope(true);
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

    /**
     * @param array<\PhpParser\Node\Stmt> $stmts
     */
    public function resolveStmts(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->resolveStmt($stmt);
        }
    }

    public function resolveStmt(\PhpParser\Node\Stmt $stmt): void
    {
        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            $this->enterScope();
            $this->resolveParams($stmt->params);
            $this->resolveStmts($stmt->stmts);
            $this->exitScope();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Block) {
            $this->enterScope();
            $this->resolveStmts($stmt->stmts);
            $this->exitScope();            
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Expression) {
            $this->resolveExpr($stmt->expr);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Return_) {
            if (!is_null($stmt->expr)) {
                $this->resolveExpr($stmt->expr);
            }
        } else {
            var_dump($stmt);
            throw new \Exception("unknown node type in stmt resolver");
        }
    }

    public function resolveExpr(\PhpParser\Node\Expr $expr): void
    {
        if ($expr instanceof \PhpParser\Node\Expr\Assign) {
            $this->resolveExpr($expr->var);
            $this->resolveExpr($expr->expr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            if (!is_string($expr->name)) {
                throw new \Exception("var name isn't string");
            }

            // TODO: don't create if on right side of expr (or global)
            $s = $this->lookupCurrentScope($expr->name);
            if (is_null($s)) {
                echo "adding " . $expr->name . PHP_EOL;
                $this->addSymbol($expr->name, "int");
            } else {
                echo "found " . $expr->name . PHP_EOL;
            }
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Int_) {

        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $this->resolveExpr($expr->left);
            $this->resolveExpr($expr->right);
        } else {
            var_dump($expr);
            throw new \Exception("unknown node type in expr resolver");
        }
    }

    /**
     * @param array<\PhpParser\Node\Param> $params
     */
    public function resolveParams(array $params): void
    {
        foreach ($params as $param) {
            $this->resolveParam($param);
        }
    }

    public function resolveParam(\PhpParser\Node\Param $param): void
    {
        $this->resolveExpr($param->var);
    }
}
