<?php

declare(strict_types=1);

namespace App\PicoHP\Pass;

use App\PicoHP\LLVM\{Module, Builder, Function_, ValueAbstract};
use App\PicoHP\LLVM\Value\Constant;

class IRGenerationPass /* extends PassInterface??? */
{
    public Module $module;
    protected Builder $builder;

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

    public function resolveStmt(\PhpParser\Node\Stmt $stmt): void
    {
        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            $function = new Function_($stmt->name->toString(), $this->module);
            $this->builder->setInsertPoint($function);
            $this->resolveParams($stmt->params);
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
            return $this->resolveExpr($expr->expr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            return new Constant(-9999, 'i32');
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
            return new Constant($expr->value, 'i32');
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
