<?php

declare(strict_types=1);

namespace App\PicoHP\Pass;

use App\PicoHP\LLVM\{Module, Builder, Function_, ValueAbstract, Type};
use App\PicoHP\LLVM\Value\{Constant, AllocaInst, Void_};
use App\PicoHP\SymbolTable\{Scope, Symbol};
use Illuminate\Support\Collection;

class IRGenerationPass /* extends PassInterface??? */
{
    public Module $module;
    protected Builder $builder;
    protected ?Scope $currentScope = null;

    /**
     * @var array<\PhpParser\Node> $stmts
     */
    protected array $stmts;

    /**
     * @param array<\PhpParser\Node> $stmts
     */
    public function __construct(array $stmts)
    {
        $this->module = new Module("test_module");
        $this->builder = $this->module->getBuilder();
        $this->stmts = $stmts;
    }

    public function exec(): void
    {
        $this->resolveStmts($this->stmts);
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

    protected function getScope(\PhpParser\Node $node): Scope
    {
        $scope = $node->getAttribute('scope');
        assert($scope instanceof Scope, "Scope not found.");
        $this->currentScope = $scope;
        return $scope;
    }

    protected function getSymbol(\PhpParser\Node $node): ?Symbol
    {
        $symbol = $node->getAttribute('symbol');
        if (!$symbol instanceof Symbol) {
            return null;
        }
        return $symbol;
    }

    public function resolveStmt(\PhpParser\Node\Stmt $stmt): void
    {
        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            $function = new Function_($stmt->name->toString(), $this->module);
            $this->builder->setInsertPoint($function);
            $this->resolveParams($stmt->params);
            $scope = $this->getScope($stmt);
            foreach ($scope->symbols as $symbol) {
                $type = null;
                switch ($symbol->type) {
                    case 'int':
                        $type = Type::INT;
                        break;
                    case 'float':
                        $type = Type::FLOAT;
                        break;
                    case 'bool':
                        $type = Type::BOOL;
                        break;
                    case 'string':
                        $type = Type::STRING;
                        break;
                }
                assert($type !== null);
                $symbol->value = $this->builder->createAlloca($symbol->name, $type);
            }
            $this->resolveStmts($stmt->stmts);
            $this->builder->endFunction();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Block) {
            $this->resolveStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Class_) {
            $this->resolveStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Expression) {
            $doc = $stmt->getDocComment();
            $this->resolveExpr($stmt->expr, $doc);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Return_) {
            if (!is_null($stmt->expr)) {
                $val = $this->resolveExpr($stmt->expr);
                $this->builder->createInstruction('ret', [$val], false);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Nop) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                $val = $this->resolveExpr($expr);
                $this->builder->createCallPrintf($val);
            }
        } else {
            throw new \Exception("unknown node type in stmt: " . get_class($stmt));
        }
    }

    public function resolveExpr(\PhpParser\Node\Expr $expr, ?\PhpParser\Comment\Doc $doc = null): ValueAbstract
    {
        if ($expr instanceof \PhpParser\Node\Expr\Assign) {
            $lval = $this->resolveExpr($expr->var); // TODO: flag left side of expression
            $rval = $this->resolveExpr($expr->expr);
            $this->builder->createStore($rval, $lval);
            return $rval;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            // if a symbol is present then space should already be allocated from when we entered this scope
            $symbol = $expr->getAttribute('symbol');
            if ($symbol instanceof Symbol && $symbol->value instanceof AllocaInst) {
                return $symbol->value;
            }

            // otherwise we are referencing an existing value we need to load
            assert($this->currentScope instanceof Scope);
            assert(is_string($expr->name));
            $symbol = $this->currentScope->lookup($expr->name);
            assert(!is_null($symbol) && $symbol->value instanceof AllocaInst);
            return $this->builder->createLoad($symbol->value);
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $lval = $this->resolveExpr($expr->left);
            $rval = $this->resolveExpr($expr->right);
            switch ($expr->getOperatorSigil()) {
                case '+':
                    $val = $this->builder->createInstruction('add', [$lval, $rval]);
                    break;
                case '*':
                    $val = $this->builder->createInstruction('mul', [$lval, $rval]);
                    break;
                case '-':
                    $val = $this->builder->createInstruction('sub', [$lval, $rval]);
                    break;
                case '/':
                    $val = $this->builder->createInstruction('sdiv', [$lval, $rval]);
                    break;
                case '&':
                    $val = $this->builder->createInstruction('and', [$lval, $rval]);
                    break;
                case '|':
                    $val = $this->builder->createInstruction('or', [$lval, $rval]);
                    break;
                case '<<':
                    $val = $this->builder->createInstruction('shl', [$lval, $rval]);
                    break;
                case '>>':
                    $val = $this->builder->createInstruction('ashr', [$lval, $rval]);
                    break;
                case '==':
                    $val = $this->builder->createInstruction('icmp eq', [$lval, $rval], resultType: Type::BOOL);
                    break;
                case '<':
                    $val = $this->builder->createInstruction('icmp slt', [$lval, $rval], resultType: Type::BOOL);
                    break;
                case '>':
                    $val = $this->builder->createInstruction('icmp sgt', [$lval, $rval], resultType: Type::BOOL);
                    break;
                default:
                    throw new \Exception("unknown BinaryOp {$expr->getOperatorSigil()}");
            }
            return $val;
        } elseif ($expr instanceof \PhpParser\Node\Expr\UnaryMinus) {
            return $this->builder->createInstruction('sub', [new Constant(0, Type::INT), $this->resolveExpr($expr->expr)]);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return new Constant($expr->value, Type::INT);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return new Constant($expr->value, Type::FLOAT);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return new Void_(); // TODO: retrieve reference from symbol table?
        } elseif ($expr instanceof \PhpParser\Node\Scalar\InterpolatedString) {
            foreach ($expr->parts as $part) {
                if ($part instanceof \PhpParser\Node\InterpolatedStringPart) {

                } else {
                    return $this->resolveExpr($part);
                }
            }
            return new Void_();
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $constName = $expr->name->toLowerString();
            return new Constant($constName === 'true' ? 1 : 0, Type::BOOL);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Int_) {
            // TODO: we seem to be introducing an extra load
            $val = $this->resolveExpr($expr->expr);

            switch ($val->getType()) {
                case Type::INT->value:
                    return $val;
                case Type::FLOAT->value:
                    return $this->builder->createFpToSi($this->resolveExpr($expr->expr));
                case Type::BOOL->value:
                    return $this->builder->createZext($this->resolveExpr($expr->expr));
                default:
                    throw new \Exception("casting to int from unknown type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Double) {
            // TODO: we seem to be introducing an extra load
            $val = $this->resolveExpr($expr->expr);

            switch ($val->getType()) {
                case Type::INT->value:
                    return $this->builder->createSiToFp($this->resolveExpr($expr->expr));
                case Type::FLOAT->value:
                    return $val;
                    // case Type::BOOL->value:
                    //     return $this->builder->createZext($this->resolveExpr($expr->expr));
                default:
                    throw new \Exception("casting to float from unknown type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            $args = (new Collection($expr->args))
                ->map(function ($arg): ValueAbstract {
                    assert($arg instanceof \PhpParser\Node\Arg);
                    return $this->resolveExpr($arg->value);
                })
                ->toArray();
            assert($expr->name instanceof \PhpParser\Node\Name);
            // TODO: figure out why phpstan thinks $args is array<mixed>
            /** @phpstan-ignore-next-line */
            return $this->builder->createCall($expr->name->name, $args, Type::INT);
        } elseif ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            assert($expr->dim !== null, "array append not implemented");
            // TODO: this won't work if the assignment is not a declaration
            $s = $this->getSymbol($expr->var);
            if (!is_null($s)) { // load
                return $this->builder->createGetElementPtr($this->resolveExpr($expr->var), $this->resolveExpr($expr->dim));
            }
            // store
            // TODO: we need to lookup in all encapsulating scopes
            assert($expr->var instanceof \PhpParser\Node\Expr\Variable);
            assert($this->currentScope !== null);
            assert(is_string($expr->var->name));
            $s = $this->currentScope->lookup($expr->var->name);
            assert($s !== null && $s->value !== null);
            return $this->builder->createLoad($this->builder->createGetElementPtr($s->value, $this->resolveExpr($expr->dim)));
        } else {
            throw new \Exception("unknown node type in expr: " . get_class($expr));
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
    }
}
