<?php

namespace App\PicoHP;

use Illuminate\Support\Arr;
use App\PicoHP\SymbolTable\{Scope, Symbol, DocTypeParser, PicoHPData};

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
    public function addSymbol(string $name, string $type): Symbol
    {
        return $this->getCurrentScope()->add(new Symbol($name, $type));
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
     * @param array<\PhpParser\Node> $stmts
     */
    public function resolveStmts(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            assert($stmt instanceof \PhpParser\Node\Stmt);
            $this->resolveStmt($stmt);
        }
    }

    public function resolveStmt(\PhpParser\Node\Stmt $stmt): void
    {
        $pData = $this->getPicoData($stmt);

        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            if ($stmt->name->name !== 'main') {
                $pData->setScope($this->enterScope());
            }
            $this->resolveParams($stmt->params);
            $this->resolveStmts($stmt->stmts);
            if ($stmt->name->name !== 'main') {
                $this->exitScope();
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Block) {
            $pData->setScope($this->enterScope());
            $this->resolveStmts($stmt->stmts);
            $this->exitScope();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Expression) {
            $doc = $stmt->getDocComment();
            $type = $this->resolveExpr($stmt->expr, $doc);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Return_) {
            // TODO: verify return type of function matches expression
            if (!is_null($stmt->expr)) {
                $this->resolveExpr($stmt->expr);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Nop) {

        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                $this->resolveExpr($expr);
            }
        } else {
            $line = $this->getLine($stmt);
            throw new \Exception("line: {$line}, unknown node type in stmt resolver: " . get_class($stmt));
        }
    }

    public function resolveExpr(\PhpParser\Node\Expr $expr, ?\PhpParser\Comment\Doc $doc = null, bool $lVal = false): string
    {
        $pData = $this->getPicoData($expr);

        if ($expr instanceof \PhpParser\Node\Expr\Assign) {
            $ltype = $this->resolveExpr($expr->var, $doc, lVal: true);
            $rtype = $this->resolveExpr($expr->expr);
            $line = $this->getLine($expr);
            assert($ltype === $rtype, "line {$line}, type mismatch in assignment");
            return $rtype;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            $pData->lVal = $lVal;
            assert(is_string($expr->name));
            $s = $this->lookupCurrentScope($expr->name);

            if (!is_null($doc) && is_null($s)) {
                $type = $this->docTypeParser->parseType($doc->getText());
                $pData->symbol = $this->addSymbol($expr->name, $type);
                return $type;
            }

            $pData->symbol = $this->lookup($expr->name);
            assert(!is_null($pData->symbol));
            return $pData->symbol->type;
        } elseif ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            $pData->lVal = $lVal;
            // add/resolve this symbol which is an array/string $var = $expr->var;
            $type = $this->resolveExpr($expr->var, $doc, lVal: $lVal);
            assert($type === 'string', "$type is not a string");
            assert($expr->dim !== null);
            assert($this->resolveExpr($expr->dim) === 'int');
            // if doc is null type will be from a retrieved value
            return "int"; // really a char/byte or maybe a single byte string?
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $ltype = $this->resolveExpr($expr->left);
            $rtype = $this->resolveExpr($expr->right);
            $line = $this->getLine($expr);
            assert($ltype === $rtype, "line {$line}, type mismatch in binary op: {$ltype} {$expr->getOperatorSigil()} {$rtype}");
            switch ($expr->getOperatorSigil()) {
                case '+':
                case '*':
                case '-':
                case '/':
                case '&':
                case '|':
                case '<<':
                case '>>':
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
        } elseif ($expr instanceof \PhpParser\Node\Expr\UnaryMinus) {
            $type = $this->resolveExpr($expr->expr);
            assert($type === "int");
            return "int";
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            // TODO: github problem?
            var_dump($pData);
            return "int";
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return "float";
        } elseif ($expr instanceof \PhpParser\Node\Scalar\String_) {
            // TODO: add to symbol table?
            return "string";
        } elseif ($expr instanceof \PhpParser\Node\Scalar\InterpolatedString) {
            foreach ($expr->parts as $part) {
                if ($part instanceof \PhpParser\Node\InterpolatedStringPart) {
                    // TODO: add string part to symbol table
                } else {
                    return $this->resolveExpr($part);
                }
            }
            return "string";
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Int_) {
            $this->resolveExpr($expr->expr);
            return "int";
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Double) {
            $this->resolveExpr($expr->expr);
            return "float";
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            return "bool";
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            // TODO: resolve the proper return type
            return "float";
        } else {
            $line = $this->getLine($expr);
            throw new \Exception("line {$line}, unknown node type in expr resolver: " . get_class($expr));
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

    protected function getPicoData(\PhpParser\Node $node): PicoHPData
    {
        if (!$node->hasAttribute("picoHP")) {
            $node->setAttribute("picoHP", new PicoHPData($this->getCurrentScope()));
        }
        return PicoHPData::getPData($node);
    }
}
