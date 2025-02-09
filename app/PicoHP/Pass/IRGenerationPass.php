<?php

declare(strict_types=1);

namespace App\PicoHP\Pass;

use App\PicoHP\{BaseType};
use App\PicoHP\LLVM\{Module, Builder, ValueAbstract, IRLine};
use App\PicoHP\LLVM\Value\{Constant, Void_, Label, Param};
use App\PicoHP\SymbolTable\{Symbol, PicoHPData};
use Illuminate\Support\Collection;

class IRGenerationPass implements \App\PicoHP\PassInterface
{
    public Module $module;
    protected Builder $builder;
    protected ?\App\PicoHP\LLVM\Function_ $currentFunction = null;

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
        $this->buildStmts($this->stmts);
    }

    /**
     * @param array<\PhpParser\Node> $stmts
     */
    public function buildStmts(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            assert($stmt instanceof \PhpParser\Node\Stmt);
            $this->buildStmt($stmt);
        }
    }

    public function buildStmt(\PhpParser\Node\Stmt $stmt): void
    {
        $pData = PicoHPData::getPData($stmt);

        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            $funcSymbol = $pData->getSymbol();
            assert($funcSymbol->func === true);
            $this->currentFunction = $this->module->addFunction($stmt->name->toString(), $funcSymbol->type, $funcSymbol->params);
            $bb = $this->currentFunction->addBasicBlock("entry");
            $this->builder->setInsertPoint($bb);
            $scope = $pData->getScope();
            foreach ($scope->symbols as $symbol) {
                if ($symbol->func) {
                    continue;
                }
                $symbol->value = $this->builder->createAlloca($symbol->name, $symbol->type->toBase());
            }
            $this->buildParams($stmt->params);
            $this->buildStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Block) {
            $scope = $pData->getScope();
            foreach ($scope->symbols as $symbol) {
                if ($symbol->func) {
                    continue;
                }
                $symbol->value = $this->builder->createAlloca($symbol->name, $symbol->type->toBase());
            }
            $this->buildStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Expression) {
            $doc = $stmt->getDocComment();
            $this->buildExpr($stmt->expr, $doc);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Return_) {
            if (!is_null($stmt->expr)) {
                $val = $this->buildExpr($stmt->expr);
                $this->builder->createInstruction('ret', [$val], false);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Nop) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                $val = $this->buildExpr($expr);
                $this->builder->createCallPrintf($val);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\If_) {
            $cond = $this->buildExpr($stmt->cond);
            assert($this->currentFunction !== null);
            $thenBB = $this->currentFunction->addBasicBlock("then{$pData->mycount}");
            $elseBB = $this->currentFunction->addBasicBlock("else{$pData->mycount}");
            $endBB = $this->currentFunction->addBasicBlock("end{$pData->mycount}");
            $thenLabel = new Label($thenBB->getName());
            $elseLabel = new Label($elseBB->getName());
            $endLabel = new Label($endBB->getName());
            $this->builder->createBranch([$cond, $thenLabel, $elseLabel]);
            $this->builder->setInsertPoint($thenBB);
            $this->buildStmts($stmt->stmts);
            $this->builder->createBranch([$endLabel]);
            $this->builder->setInsertPoint($elseBB);
            if (!is_null($stmt->else)) {
                $this->buildStmts($stmt->else->stmts);
            }
            $this->builder->createBranch([$endLabel]);
            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\While_) {
            assert($this->currentFunction !== null);
            $condBB = $this->currentFunction->addBasicBlock("cond{$pData->mycount}");
            $bodyBB = $this->currentFunction->addBasicBlock("body{$pData->mycount}");
            $endBB = $this->currentFunction->addBasicBlock("end{$pData->mycount}");
            $condLabel = new Label($condBB->getName());
            $bodyLabel = new Label($bodyBB->getName());
            $endLabel = new Label($endBB->getName());
            $this->builder->createBranch([$condLabel]);
            $this->builder->setInsertPoint($condBB);
            $cond = $this->buildExpr($stmt->cond);
            $this->builder->createBranch([$cond, $bodyLabel, $endLabel]);
            $this->builder->setInsertPoint($bodyBB);
            $this->buildStmts($stmt->stmts);
            $this->builder->createBranch([$condLabel]);
            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Class_) {
            // TODO: generate struct type
            assert($stmt->name instanceof \PhpParser\Node\Identifier);
            $className = $stmt->name->toString();
            $this->module->addLine(new IRLine("%struct.$className = type {"));
            $this->buildStmts($stmt->stmts);
            $this->module->addLine(new IRLine("}"));
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
            dump($pData);
            // this is dumb just join the types
            $this->module->addLine(new IRLine("i32"));
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Do_) {
            assert($this->currentFunction !== null);
            $condBB = $this->currentFunction->addBasicBlock("cond{$pData->mycount}");
            $bodyBB = $this->currentFunction->addBasicBlock("body{$pData->mycount}");
            $endBB = $this->currentFunction->addBasicBlock("end{$pData->mycount}");
            $condLabel = new Label($condBB->getName());
            $bodyLabel = new Label($bodyBB->getName());
            $endLabel = new Label($endBB->getName());
            $this->builder->createBranch([$bodyLabel]);
            $this->builder->setInsertPoint($bodyBB);
            $this->buildStmts($stmt->stmts);
            $this->builder->createBranch([$condLabel]);
            $this->builder->setInsertPoint($condBB);
            $cond = $this->buildExpr($stmt->cond);
            $this->builder->createBranch([$cond, $bodyLabel, $endLabel]);
            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\For_) {
            assert($this->currentFunction !== null);
            $condBB = $this->currentFunction->addBasicBlock("cond{$pData->mycount}");
            $bodyBB = $this->currentFunction->addBasicBlock("body{$pData->mycount}");
            $endBB = $this->currentFunction->addBasicBlock("end{$pData->mycount}");
            $condLabel = new Label($condBB->getName());
            $bodyLabel = new Label($bodyBB->getName());
            $endLabel = new Label($endBB->getName());
            foreach ($stmt->init as $init) {
                $this->buildExpr($init);
            }
            $this->builder->createBranch([$condLabel]);
            $this->builder->setInsertPoint($condBB);
            $conds = [];
            foreach ($stmt->cond as $cond) {
                $conds[] = $this->buildExpr($cond);
            }
            assert(count($conds) > 0);
            $this->builder->createBranch([$conds[0], $bodyLabel, $endLabel]);
            $this->builder->setInsertPoint($bodyBB);
            $this->buildStmts($stmt->stmts);
            foreach ($stmt->loop as $loop) {
                $this->buildExpr($loop);
            }
            $this->builder->createBranch([$condLabel]);
            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Interface_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
            $this->buildStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\InlineHTML) {
            // TODO: create string constant?
        } else {
            throw new \Exception("unknown node type in stmt: " . get_class($stmt));
        }
    }

    public function buildExpr(\PhpParser\Node\Expr $expr, ?\PhpParser\Comment\Doc $doc = null): ValueAbstract
    {
        $pData = PicoHPData::getPData($expr);

        if ($expr instanceof \PhpParser\Node\Expr\Assign) {
            $lval = $this->buildExpr($expr->var);
            $rval = $this->buildExpr($expr->expr);
            $this->builder->createStore($rval, $lval);
            return $rval;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            $value = $pData->getValue();
            if ($pData->lVal) {
                return $value;
            }
            return $this->builder->createLoad($value);
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $lval = $this->buildExpr($expr->left);
            $rval = $this->buildExpr($expr->right);
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
                    $val = $this->builder->createInstruction('icmp eq', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '<':
                    $val = $this->builder->createInstruction('icmp slt', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '>':
                    $val = $this->builder->createInstruction('icmp sgt', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                default:
                    throw new \Exception("unknown BinaryOp {$expr->getOperatorSigil()}");
            }
            return $val;
        } elseif ($expr instanceof \PhpParser\Node\Expr\UnaryMinus) {
            return $this->builder->createInstruction('sub', [new Constant(0, BaseType::INT), $this->buildExpr($expr->expr)]);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return new Constant($expr->value, BaseType::INT);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return new Constant($expr->value, BaseType::FLOAT);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return new Void_(); // TODO: retrieve reference from symbol table?
        } elseif ($expr instanceof \PhpParser\Node\Scalar\InterpolatedString) {
            foreach ($expr->parts as $part) {
                if ($part instanceof \PhpParser\Node\InterpolatedStringPart) {

                } else {
                    return $this->buildExpr($part);
                }
            }
            return new Void_();
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $constName = $expr->name->toLowerString();
            return new Constant($constName === 'true' ? 1 : 0, BaseType::BOOL);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Int_) {
            $val = $this->buildExpr($expr->expr);

            switch ($val->getType()) {
                case BaseType::INT:
                    return $val;
                case BaseType::FLOAT:
                    return $this->builder->createFpToSi($val);
                case BaseType::BOOL:
                    return $this->builder->createZext($val);
                default:
                    throw new \Exception("casting to int from unknown type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Double) {
            $val = $this->buildExpr($expr->expr);

            switch ($val->getType()) {
                case BaseType::INT:
                    return $this->builder->createSiToFp($val);
                case BaseType::FLOAT:
                    return $val;
                case BaseType::BOOL:
                    return $this->builder->createSiToFp($this->builder->createZext($val));
                default:
                    throw new \Exception("casting to float from unknown type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            // TODO: make sure args match function signature
            $args = (new Collection($expr->args))
                ->map(function ($arg): ValueAbstract {
                    assert($arg instanceof \PhpParser\Node\Arg);
                    return $this->buildExpr($arg->value);
                })
                ->toArray();
            assert($expr->name instanceof \PhpParser\Node\Name);
            // TODO: figure out why phpstan thinks $args is array<mixed>
            /** @phpstan-ignore-next-line */
            return $this->builder->createCall($expr->name->name, $args, BaseType::INT);
        } elseif ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            assert($expr->dim !== null, "array append not implemented");
            $varData = PicoHPData::getPData($expr->var);
            if ($pData->lVal === true) {
                return $this->builder->createGetElementPtr(
                    $varData->getValue(),
                    $this->buildExpr($expr->dim)
                );
            }
            return $this->builder->createLoad(
                $this->builder->createGetElementPtr(
                    $varData->getValue(),
                    $this->buildExpr($expr->dim)
                )
            );
        } elseif ($expr instanceof \PhpParser\Node\Expr\Include_) {
            return new Void_();
        } elseif ($expr instanceof \PhpParser\Node\Expr\PostInc) {
            return new Constant(1, BaseType::INT);
        } elseif ($expr instanceof \PhpParser\Node\Expr\PostDec) {
            return new Constant(0, BaseType::INT);
        } else {
            throw new \Exception("unknown node type in expr: " . get_class($expr));
        }
    }

    /**
     * @param array<\PhpParser\Node\Param> $params
     */
    public function buildParams(array $params): void
    {
        $count = 0;
        foreach ($params as $param) {
            $pData = PicoHPData::getPData($param);
            $type = $pData->getSymbol()->type;
            $this->builder->createStore(new Param($count++, $type->toBase()), $pData->getValue());
        }
    }
}
