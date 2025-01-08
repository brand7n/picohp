<?php

declare(strict_types=1);

namespace App\PicoHP\Pass;

use App\PicoHP\LLVM\{Module, Builder, Function_, ValueAbstract, Type};
use App\PicoHP\LLVM\Value\{Constant, AllocaInst};
use App\PicoHP\SymbolTable\{Scope, Symbol};

class IRGenerationPass /* extends PassInterface??? */
{
    public Module $module;
    protected Builder $builder;
    protected ?Scope $currentScope = null;

    public function __construct()
    {
        $this->module = new Module("test_module");
        $this->builder = $this->module->getBuilder();
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

    protected function getScope(\PhpParser\Node $node): Scope
    {
        $scope = $node->getAttribute('scope');
        if (!$scope instanceof Scope) {
            throw new \Exception("scope not found");
        }
        $this->currentScope = $scope;
        return $scope;
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
                }
                assert($type !== null);
                $symbol->value = $this->builder->createAlloca($symbol->name, $type);
            }
            $this->resolveStmts($stmt->stmts);
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
        } else {
            throw new \Exception("unknown node type in stmt: " . $stmt->getType());
        }
    }

    public function resolveExpr(\PhpParser\Node\Expr $expr, ?\PhpParser\Comment\Doc $doc = null): ValueAbstract
    {
        if ($expr instanceof \PhpParser\Node\Expr\Assign) {
            $lval = $this->resolveExpr($expr->var);
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
                default:
                    throw new \Exception("unknown BinaryOp {$expr->getOperatorSigil()}");
            }
            return $val;
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return new Constant($expr->value, Type::INT);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return new Constant($expr->value, Type::FLOAT);
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $constName = $expr->name->toLowerString();
            return new Constant($constName === 'true' ? 1 : 0, Type::BOOL);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Int_) {
            $val = $this->resolveExpr($expr->expr);
            if ($val->getType() === Type::INT->value) {
                return $val;
            }
            return $this->builder->createFpToSi($this->resolveExpr($expr->expr));
        } else {
            throw new \Exception("unknown node type in expr: " . $expr->getType());
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
