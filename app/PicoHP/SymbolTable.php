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
        if (count($this->scopes) === 1) {
            throw new \Exception("can't pop global scope");
        }
        if (array_pop($this->scopes) === null) {
            throw new \Exception("scope stack is empty");
        }
    }

    /**
     * Add a symbol to the symbol table.
     */
    public function addSymbol(string $name, string $type, mixed $value = null): Symbol
    {
        return $this->getCurrentScope()->add(new Symbol($name, $type, $value));
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

    //TODO: move into pass/resolver class
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
            $stmt->setAttribute("scope", $this->enterScope());
            $this->resolveParams($stmt->params);
            $this->resolveStmts($stmt->stmts);
            $this->exitScope();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Block) {
            $stmt->setAttribute("scope", $this->enterScope());
            $this->resolveStmts($stmt->stmts);
            $this->exitScope();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Class_) {
            $stmt->setAttribute("scope", $this->enterScope());
            $this->resolveStmts($stmt->stmts);
            $this->exitScope();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Expression) {
            $doc = $stmt->getDocComment();
            $this->resolveExpr($stmt->expr, $doc);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Return_) {
            if (!is_null($stmt->expr)) {
                $this->resolveExpr($stmt->expr);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Nop) {
        } else {
            throw new \Exception("unknown node type in stmt resolver: " . $stmt->getType());
        }
    }

    public function resolveExpr(\PhpParser\Node\Expr $expr, ?\PhpParser\Comment\Doc $doc = null): void
    {
        if ($expr instanceof \PhpParser\Node\Expr\Assign) {
            $this->resolveExpr($expr->var, $doc);
            $this->resolveExpr($expr->expr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            if (!is_string($expr->name)) {
                throw new \Exception("var name isn't string");
            }

            $s = $this->lookupCurrentScope($expr->name);

            if (!is_null($doc) && is_null($s)) {
                // TODO: parse type from doc
                //echo $doc->getText() . PHP_EOL;

                $s = $this->addSymbol($expr->name, "int");
                $expr->setAttribute("symbol", $s);
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $this->resolveExpr($expr->left);
            $this->resolveExpr($expr->right);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Int_) {
        } else {
            throw new \Exception("unknown node type in expr resolver: " . $expr->getType());
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
        // TODO: fix this
        // for now supply the emptpy Doc node so the parameter is added to the symbol table
        $this->resolveExpr($param->var, new \PhpParser\Comment\Doc(''));
    }
}
