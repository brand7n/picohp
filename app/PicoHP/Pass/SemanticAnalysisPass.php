<?php

namespace App\PicoHP\Pass;

use App\PicoHP\{PassInterface, SymbolTable};
use App\PicoHP\SymbolTable\{DocTypeParser, PicoHPData};

class SemanticAnalysisPass implements PassInterface
{
    /**
     * @var array<\PhpParser\Node>
     */
    protected array $ast;

    protected SymbolTable $symbolTable;
    protected DocTypeParser $docTypeParser;

    /**
     * @param array<\PhpParser\Node> $ast
     */
    public function __construct(array $ast)
    {
        $this->ast = $ast;
        $this->symbolTable = new SymbolTable();
        $this->docTypeParser = new DocTypeParser();
    }

    public function exec(): void
    {
        $this->resolveStmts($this->ast);
    }

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
            $pData->symbol = $this->symbolTable->addSymbol($stmt->name->name, $returnType, func: true);
            if ($stmt->name->name !== 'main') {
                $pData->setScope($this->symbolTable->enterScope());
            }

            $pData->getSymbol()->params = $this->resolveParams($stmt->params);
            $this->resolveStmts($stmt->stmts);

            if ($stmt->name->name !== 'main') {
                $this->symbolTable->exitScope();
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Block) {
            $pData->setScope($this->symbolTable->enterScope());
            $this->resolveStmts($stmt->stmts);
            $this->symbolTable->exitScope();
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
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Do_) {
            $this->resolveStmts($stmt->stmts);
            $this->resolveExpr($stmt->cond);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\For_) {
            foreach ($stmt->init as $init) {
                $this->resolveExpr($init);
            }
            foreach ($stmt->cond as $cond) {
                assert($this->resolveExpr($cond) === 'bool');
            }
            foreach ($stmt->loop as $loop) {
                $this->resolveExpr($loop);
            }
            $this->resolveStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Foreach_) {

        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Class_) {
            $pData->setScope($this->symbolTable->enterScope());
            $this->resolveStmts($stmt->stmts);
            $this->symbolTable->exitScope();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
            assert($stmt->type instanceof \PhpParser\Node\Identifier);
            foreach ($stmt->props as $prop) {
                $this->resolveProperty($prop, $pData, $stmt->type->name);
            }
        } else {
            $line = $this->getLine($stmt);
            throw new \Exception("line: {$line}, unknown node type in stmt resolver: " . get_class($stmt));
        }
    }

    public function resolveExpr(\PhpParser\Node\Expr $expr, ?\PhpParser\Comment\Doc $doc = null, bool $lVal = false, ?string $rType = null): string
    {
        $pData = $this->getPicoData($expr);

        if ($expr instanceof \PhpParser\Node\Expr\Assign) {
            $rtype = $this->resolveExpr($expr->expr);
            $ltype = $this->resolveExpr($expr->var, $doc, lVal: true, rType: $rtype);
            $line = $this->getLine($expr);
            assert($ltype === $rtype, "line {$line}, type mismatch in assignment");
            return $rtype;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            $pData->lVal = $lVal;
            assert(is_string($expr->name));
            $s = $this->symbolTable->lookupCurrentScope($expr->name);

            if (!is_null($doc) && is_null($s)) {
                $type = $this->docTypeParser->parseType($doc->getText());
                $pData->symbol = $this->symbolTable->addSymbol($expr->name, $type);
                return $type;
            } elseif (!is_null($rType) && is_null($s)) {
                $pData->symbol = $this->symbolTable->addSymbol($expr->name, $rType);
                return $rType;
            }

            $pData->symbol = $this->symbolTable->lookup($expr->name);
            $line = $this->getLine($expr);
            assert(!is_null($pData->symbol), "line {$line}, symbol not found: {$expr->name}");
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
            $s = $this->symbolTable->lookup($expr->name->name);
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
            $pData->symbol = $this->symbolTable->addSymbol($param->var->name, $param->type->name);
            $paramTypes[] = $param->type->name;
        }
        return $paramTypes;
    }

    public function resolveProperty(\PhpParser\Node\Stmt\PropertyProperty $prop, PicoHPData $pData, string $type): void
    {
        if ($prop->default !== null) {
            assert($this->resolveExpr($prop->default) === $type);
        }
        $pData->symbol = $this->symbolTable->addSymbol($prop->name, $type);
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
            $node->setAttribute("picoHP", new PicoHPData($this->symbolTable->getCurrentScope()));
        }
        return PicoHPData::getPData($node);
    }
}
