<?php

namespace App\PicoHP;

use Illuminate\Support\Arr;
use App\PicoHP\SymbolTable\{Scope, Symbol, DocTypeParser};

class SymbolTable
{
    /**
     * @var array<Scope>
     */
    protected array $scopes = [];

    protected DocTypeParser $docTypeParser;

    public function __construct()
    {
        // push global scope on the stack
        $this->scopes[] = new Scope(true);

        $this->docTypeParser = new DocTypeParser();
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
    public function addSymbol(string $name, string $type, mixed $value = null): Symbol
    {
        // echo "addSymbol {$name} {$type}" . PHP_EOL;
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
        assert($scope !== null);
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
            $type = $this->resolveExpr($stmt->expr, $doc);
            //echo "stmt expr type: {$type}" . PHP_EOL;
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Return_) {
            // TODO: verify return type of function matches expression
            if (!is_null($stmt->expr)) {
                $this->resolveExpr($stmt->expr);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Nop) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                $this->resolveExpr($expr);
            }
        } else {
            $line = $this->getLine($stmt);
            throw new \Exception("line: {$line}, unknown node type in stmt resolver: " . $stmt->getType());
        }
    }

    public function resolveExpr(\PhpParser\Node\Expr $expr, ?\PhpParser\Comment\Doc $doc = null): string
    {
        if ($expr instanceof \PhpParser\Node\Expr\Assign) {
            $ltype = $this->resolveExpr($expr->var, $doc);
            $rtype = $this->resolveExpr($expr->expr);
            $line = $this->getLine($expr);
            assert($ltype === $rtype, "line {$line}, type mismatch in assignment");
            return $rtype;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            assert(is_string($expr->name));
            $s = $this->lookupCurrentScope($expr->name);

            if (!is_null($doc) && is_null($s)) {
                $type = $this->docTypeParser->parseType($doc->getText());
                $s = $this->addSymbol($expr->name, $type);
                $expr->setAttribute("symbol", $s);
                return $type;
            }
            if (is_null($s)) {
                var_dump($this->getCurrentScope());
            }
            assert(!is_null($s), "Need to implement nested blocks.");
            return $s->type;
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $ltype = $this->resolveExpr($expr->left);
            $rtype = $this->resolveExpr($expr->right);
            $line = $this->getLine($expr);
            assert($ltype === $rtype, "line {$line}, type mismatch in binary op " . $expr->getOperatorSigil());
            switch ($expr->getOperatorSigil()) {
                case '+':
                case '*':
                case '-':
                case '/':
                case '&':
                case '|':
                    $type = $rtype;
                    break;
                case '==':
                case '<':
                case '>':
                    $type = 'bool';
                    break;
                default:
                    throw new \Exception("unknown BinaryOp {$expr->getOperatorSigil()}");
            }
            return $type;
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return "int";
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return "float";
        } elseif ($expr instanceof \PhpParser\Node\Scalar\String_) {
            // TODO: add to symbol table?
            return "string";
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Int_) {
            return "int";
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Double) {
            return "float";
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            return "bool";
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            // TODO: resolve the proper return type
            return "float";
        } else {
            $line = $this->getLine($expr);
            throw new \Exception("line {$line}, unknown node type in expr resolver: " . $expr->getType());
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

    protected function getLine(\PhpParser\Node $node): int
    {
        $line = 0;
        if ($node->hasAttribute("startLine")) {
            $line = $node->getAttribute("startLine");
            assert(is_int($line));
        }
        return $line;
    }
}
