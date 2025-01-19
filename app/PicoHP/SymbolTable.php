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
            $returnType = 'int';
            if (!is_null($stmt->returnType)) {
                assert($stmt->returnType instanceof \PhpParser\Node\Identifier);
                $returnType = $stmt->returnType->name;
            }
            $pData->symbol = $this->addSymbol($stmt->name->name, $returnType, func: true);
            if ($stmt->name->name !== 'main') {
                $pData->setScope($this->enterScope());
            }

            $pData->getSymbol()->params = $this->resolveParams($stmt->params);
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
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\If_) {
            $this->resolveExpr($stmt->cond);
            $this->resolveStmts($stmt->stmts);
            if (!is_null($stmt->else)) {
                $this->resolveStmts($stmt->else->stmts);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\While_) {
            $this->resolveExpr($stmt->cond);
            $this->resolveStmts($stmt->stmts);
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
            $dimType = $this->resolveExpr($expr->dim);
            assert($dimType === 'int');
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
            $argTypes = $this->resolveArgs($expr->args);
            assert($expr->name instanceof \PhpParser\Node\Name);
            $s = $this->lookup($expr->name->name);
            //assert(is_string($s->type));
            return "int";//$s->type;
        } else {
            $line = $this->getLine($expr);
            throw new \Exception("line {$line}, unknown node type in expr resolver: " . get_class($expr));
        }
    }

    /**
     * @param array<\PhpParser\Node\Arg|\PhpParser\Node\VariadicPlaceholder> $args
     * @return array<string>
     */
    public function resolveArgs(array $args): array
    {
        $argTypes = [];
        foreach ($args as $arg) {
            assert($arg instanceof \PhpParser\Node\Arg);
            $argTypes[] = $this->resolveExpr($arg->value);
        }
        return $argTypes;
    }

    /**
     * @param array<\PhpParser\Node\Param> $params
     * @return array<string>
     */
    public function resolveParams(array $params): array
    {
        $paramTypes = [];
        foreach ($params as $param) {
            $pData = $this->getPicoData($param);
            assert($param->var instanceof \PhpParser\Node\Expr\Variable);
            assert(is_string($param->var->name));
            assert($param->type instanceof \PhpParser\Node\Identifier);
            $pData->symbol = $this->addSymbol($param->var->name, $param->type->name);
            $paramTypes[] = $param->type->name;
        }
        return $paramTypes;
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
