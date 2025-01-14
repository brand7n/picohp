<?php

declare(strict_types=1);

namespace App\PicoHP\Pass;

use App\PicoHP\LLVM\{Module, Builder, Function_, ValueAbstract, Type};
use App\PicoHP\LLVM\Value\{Constant, Void_};
use App\PicoHP\SymbolTable\{Symbol, PicoHPData};
use Illuminate\Support\Collection;

class IRGenerationPass /* extends PassInterface??? */
{
    public Module $module;
    protected Builder $builder;

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
            $function = new Function_($stmt->name->toString(), $this->module);
            $this->builder->setInsertPoint($function);
            $this->buildParams($stmt->params);
            $scope = $pData->getScope();
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
            $this->buildStmts($stmt->stmts);
            $this->builder->endFunction();
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Block) {
            $this->buildStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Expression) {
            $doc = $stmt->getDocComment();
            $this->buildExpr($stmt->expr, $doc);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Return_) {
            if (!is_null($stmt->expr)) {
                $val = $this->buildExpr($stmt->expr);
                $this->builder->createInstruction('ret', [$val], false);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Nop) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                $val = $this->buildExpr($expr);
                $this->builder->createCallPrintf($val);
            }
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
            return $this->builder->createInstruction('sub', [new Constant(0, Type::INT), $this->buildExpr($expr->expr)]);
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
                    return $this->buildExpr($part);
                }
            }
            return new Void_();
        } elseif ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $constName = $expr->name->toLowerString();
            return new Constant($constName === 'true' ? 1 : 0, Type::BOOL);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Int_) {
            // TODO: we seem to be introducing an extra load
            $val = $this->buildExpr($expr->expr);

            switch ($val->getType()) {
                case Type::INT->value:
                    return $val;
                case Type::FLOAT->value:
                    return $this->builder->createFpToSi($this->buildExpr($expr->expr));
                case Type::BOOL->value:
                    return $this->builder->createZext($this->buildExpr($expr->expr));
                default:
                    throw new \Exception("casting to int from unknown type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Double) {
            // TODO: we seem to be introducing an extra load
            $val = $this->buildExpr($expr->expr);

            switch ($val->getType()) {
                case Type::INT->value:
                    return $this->builder->createSiToFp($this->buildExpr($expr->expr));
                case Type::FLOAT->value:
                    return $val;
                    // case Type::BOOL->value:
                    //     return $this->builder->createZext($this->buildExpr($expr->expr));
                default:
                    throw new \Exception("casting to float from unknown type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            $args = (new Collection($expr->args))
                ->map(function ($arg): ValueAbstract {
                    assert($arg instanceof \PhpParser\Node\Arg);
                    return $this->buildExpr($arg->value);
                })
                ->toArray();
            assert($expr->name instanceof \PhpParser\Node\Name);
            // TODO: figure out why phpstan thinks $args is array<mixed>
            /** @phpstan-ignore-next-line */
            return $this->builder->createCall($expr->name->name, $args, Type::INT);
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
        } else {
            throw new \Exception("unknown node type in expr: " . get_class($expr));
        }
    }

    /**
     * @param array<\PhpParser\Node\Param> $params
     */
    public function buildParams(array $params): void
    {
        foreach ($params as $param) {
            $this->buildParam($param);
        }
    }

    public function buildParam(\PhpParser\Node\Param $param): void
    {
    }
}
