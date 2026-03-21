<?php

declare(strict_types=1);

namespace App\PicoHP\Pass;

use App\PicoHP\{BaseType};
use App\PicoHP\LLVM\{Module, Builder, ValueAbstract, IRLine};
use App\PicoHP\LLVM\Value\{Constant, Void_, Label, Param, NullConstant};
use App\PicoHP\SymbolTable\{ClassMetadata, PicoHPData};
use Illuminate\Support\Collection;

class IRGenerationPass implements \App\PicoHP\PassInterface
{
    public Module $module;
    protected Builder $builder;
    protected ?\App\PicoHP\LLVM\Function_ $currentFunction = null;
    protected ?string $currentClassName = null;
    protected ?ValueAbstract $currentThisPtr = null;

    /**
     * @var array<\PhpParser\Node> $stmts
     */
    protected array $stmts;

    /** @var array<string, ClassMetadata> */
    protected array $classRegistry = [];

    /**
     * @param array<\PhpParser\Node> $stmts
     * @param array<string, ClassMetadata> $classRegistry
     */
    public function __construct(array $stmts, array $classRegistry = [])
    {
        $this->module = new Module("test_module");
        $this->builder = $this->module->getBuilder();
        $this->stmts = $stmts;
        $this->classRegistry = $classRegistry;
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
                $symbol->value = $this->buildSymbolAlloca($symbol);
            }
            $this->buildParams($stmt->params);
            $this->buildStmts($stmt->stmts);
            if ($funcSymbol->type->toBase() === BaseType::VOID) {
                $this->builder->createRetVoid();
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Block) {
            $scope = $pData->getScope();
            foreach ($scope->symbols as $symbol) {
                if ($symbol->func) {
                    continue;
                }
                $symbol->value = $this->buildSymbolAlloca($symbol);
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
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Declare_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                $val = $this->buildExpr($expr);
                if ($val->getType() === BaseType::BOOL) {
                    assert($this->currentFunction !== null);
                    $count = $pData->mycount;
                    $printBB = $this->currentFunction->addBasicBlock("echo_bool{$count}");
                    $endBB = $this->currentFunction->addBasicBlock("echo_end{$count}");
                    $printLabel = new Label($printBB->getName());
                    $endLabel = new Label($endBB->getName());
                    $this->builder->createBranch([$val, $printLabel, $endLabel]);
                    $this->builder->setInsertPoint($printBB);
                    $intVal = $this->builder->createZext($val);
                    $this->builder->createCallPrintf($intVal);
                    $this->builder->createBranch([$endLabel]);
                    $this->builder->setInsertPoint($endBB);
                } else {
                    $this->builder->createCallPrintf($val);
                }
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\If_) {
            assert($this->currentFunction !== null);
            $currentFunction = $this->currentFunction;
            $count = $pData->mycount;
            $endBB = $currentFunction->addBasicBlock("end{$count}");
            $endLabel = new Label($endBB->getName());

            $thenBB = $currentFunction->addBasicBlock("then{$count}");
            $thenLabel = new Label($thenBB->getName());

            // Determine where to branch on false: first elseif, else, or end
            $elseifCount = count($stmt->elseifs);
            if ($elseifCount > 0) {
                $nextBB = $currentFunction->addBasicBlock("elseif_cond{$count}_0");
            } elseif (!is_null($stmt->else)) {
                $nextBB = $currentFunction->addBasicBlock("else{$count}");
            } else {
                $nextBB = $endBB;
            }
            $nextLabel = new Label($nextBB->getName());

            $cond = $this->buildExpr($stmt->cond);
            $this->builder->createBranch([$cond, $thenLabel, $nextLabel]);

            // then block
            $this->builder->setInsertPoint($thenBB);
            $this->buildStmts($stmt->stmts);
            $this->builder->createBranch([$endLabel]);

            // elseif blocks
            for ($i = 0; $i < $elseifCount; $i++) {
                $elseif = $stmt->elseifs[$i];
                $this->builder->setInsertPoint($nextBB);
                $elseifCond = $this->buildExpr($elseif->cond);

                $bodyBB = $currentFunction->addBasicBlock("elseif_body{$count}_{$i}");
                $bodyLabel = new Label($bodyBB->getName());

                // Determine next target after this elseif
                if ($i + 1 < $elseifCount) {
                    $nextBB = $currentFunction->addBasicBlock("elseif_cond{$count}_" . ($i + 1));
                } elseif (!is_null($stmt->else)) {
                    $nextBB = $currentFunction->addBasicBlock("else{$count}");
                } else {
                    $nextBB = $endBB;
                }
                $nextLabel = new Label($nextBB->getName());

                $this->builder->createBranch([$elseifCond, $bodyLabel, $nextLabel]);

                $this->builder->setInsertPoint($bodyBB);
                $this->buildStmts($elseif->stmts);
                $this->builder->createBranch([$endLabel]);
            }

            // else block
            if (!is_null($stmt->else)) {
                $this->builder->setInsertPoint($nextBB);
                $this->buildStmts($stmt->else->stmts);
                $this->builder->createBranch([$endLabel]);
            }

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
            assert($stmt->name instanceof \PhpParser\Node\Identifier);
            $className = $stmt->name->toString();
            assert(isset($this->classRegistry[$className]));
            $classMeta = $this->classRegistry[$className];
            $fields = $classMeta->toLLVMStructFields();
            $this->module->addLine(new IRLine("%struct.{$className} = type { {$fields} }"));
            $this->currentClassName = $className;
            $this->buildStmts($stmt->stmts);
            $this->currentClassName = null;
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
            // Handled by struct type definition
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
            assert($this->currentClassName !== null);
            $methodName = $stmt->name->toString();
            $funcSymbol = $pData->getSymbol();
            $qualifiedName = "{$this->currentClassName}_{$methodName}";
            // Methods get $this (ptr) as first param
            $thisParam = new \App\PicoHP\PicoType(\App\PicoHP\BaseType::PTR);
            $allParams = array_merge([$thisParam], $funcSymbol->params);
            $this->currentFunction = $this->module->addFunction($qualifiedName, $funcSymbol->type, $allParams);
            $bb = $this->currentFunction->addBasicBlock("entry");
            $this->builder->setInsertPoint($bb);
            $scope = $pData->getScope();
            foreach ($scope->symbols as $symbol) {
                if ($symbol->func) {
                    continue;
                }
                $symbol->value = $this->buildSymbolAlloca($symbol);
            }
            // Store $this param (param 0) into its alloca
            $thisSymbol = $scope->symbols['this'] ?? null;
            assert($thisSymbol !== null);
            assert($thisSymbol->value !== null);
            $this->builder->createStore(new Param(0, \App\PicoHP\BaseType::PTR), $thisSymbol->value);
            $this->currentThisPtr = $thisSymbol->value;
            // Store remaining params (offset by 1)
            $paramIndex = 1;
            foreach ($stmt->params as $param) {
                $paramPData = PicoHPData::getPData($param);
                $type = $paramPData->getSymbol()->type;
                $this->builder->createStore(new Param($paramIndex++, $type->toBase()), $paramPData->getValue());
            }
            assert($stmt->stmts !== null);
            $this->buildStmts($stmt->stmts);
            if ($funcSymbol->type->toBase() === \App\PicoHP\BaseType::VOID) {
                $this->builder->createRetVoid();
            }
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
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Foreach_) {
            assert($this->currentFunction !== null);
            $count = $pData->mycount;

            // Load array pointer (works for variables, property fetches, etc.)
            $arrayPtr = $this->buildExpr($stmt->expr);
            $arrayType = $this->getExprResolvedType($stmt->expr);

            assert($stmt->valueVar instanceof \PhpParser\Node\Expr\Variable);
            $valueVarPData = PicoHPData::getPData($stmt->valueVar);
            $valuePtr = $valueVarPData->getValue();

            $counterPtr = $this->builder->createAlloca("foreach_i{$count}", BaseType::INT);
            $this->builder->createStore(new Constant(0, BaseType::INT), $counterPtr);

            $condBB = $this->currentFunction->addBasicBlock("foreach_cond{$count}");
            $bodyBB = $this->currentFunction->addBasicBlock("foreach_body{$count}");
            $endBB = $this->currentFunction->addBasicBlock("foreach_end{$count}");
            $condLabel = new Label($condBB->getName());
            $bodyLabel = new Label($bodyBB->getName());
            $endLabel = new Label($endBB->getName());

            $this->builder->createBranch([$condLabel]);

            $this->builder->setInsertPoint($condBB);
            $idx = $this->builder->createLoad($counterPtr);
            $lenVal = $this->builder->createArrayLen($arrayPtr);
            $cond = $this->builder->createInstruction('icmp slt', [$idx, $lenVal], resultType: BaseType::BOOL);
            $this->builder->createBranch([$cond, $bodyLabel, $endLabel]);

            $this->builder->setInsertPoint($bodyBB);
            $elemVal = $this->builder->createArrayGet($arrayPtr, $idx, $arrayType->getElementBaseType());
            $this->builder->createStore($elemVal, $valuePtr);
            $this->buildStmts($stmt->stmts);
            $idxNext = $this->builder->createInstruction('add', [$idx, new Constant(1, BaseType::INT)]);
            $this->builder->createStore($idxNext, $counterPtr);
            $this->builder->createBranch([$condLabel]);

            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Interface_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Use_) {
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
            // Array literal: $arr = [1, 2, 3]
            if ($expr->expr instanceof \PhpParser\Node\Expr\Array_) {
                $lval = $this->buildExpr($expr->var);
                $arrayType = $this->getExprResolvedType($expr->var);
                $arrPtr = $this->buildArrayInit($expr->expr, $arrayType);
                $this->builder->createStore($arrPtr, $lval);
                return $arrPtr;
            }
            // Array element write: $arr[idx] = val or $arr[] = val
            if ($expr->var instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
                $rval = $this->buildExpr($expr->expr);
                // buildExpr in lVal context returns a pointer TO the array ptr — load it
                $arrVarExpr = $expr->var->var;
                $arrPtrPtr = $this->buildExpr($arrVarExpr);
                $arrPtr = $this->builder->createLoad($arrPtrPtr);
                $arrayType = $this->getExprResolvedType($arrVarExpr);
                if ($expr->var->dim === null) {
                    // $arr[] = val (push)
                    $this->builder->createArrayPush($arrPtr, $rval, $arrayType->getElementBaseType());
                } else {
                    // $arr[idx] = val (set)
                    $idx = $this->buildExpr($expr->var->dim);
                    $this->builder->createArraySet($arrPtr, $idx, $rval, $arrayType->getElementBaseType());
                }
                return $rval;
            }
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
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp\Coalesce) {
            $lval = $this->buildExpr($expr->left);
            $rval = $this->buildExpr($expr->right);
            $isNull = $this->builder->createNullCheck($lval);
            return $this->builder->createSelect($isNull, $rval, $lval);
        } elseif ($expr instanceof \PhpParser\Node\Expr\BinaryOp) {
            $sigil = $expr->getOperatorSigil();

            // Short-circuit evaluation for && and ||
            if ($sigil === '&&' || $sigil === '||') {
                return $this->buildShortCircuit($expr, $pData);
            }

            $lval = $this->buildExpr($expr->left);
            $rval = $this->buildExpr($expr->right);

            if ($sigil === '.') {
                return $this->builder->createStringConcat($lval, $rval);
            }

            $isFloat = $lval->getType() === BaseType::FLOAT;
            $operandType = $lval->getType();
            switch ($sigil) {
                case '+':
                    $val = $this->builder->createInstruction($isFloat ? 'fadd' : 'add', [$lval, $rval], resultType: $operandType);
                    break;
                case '*':
                    $val = $this->builder->createInstruction($isFloat ? 'fmul' : 'mul', [$lval, $rval], resultType: $operandType);
                    break;
                case '-':
                    $val = $this->builder->createInstruction($isFloat ? 'fsub' : 'sub', [$lval, $rval], resultType: $operandType);
                    break;
                case '/':
                    $val = $this->builder->createInstruction($isFloat ? 'fdiv' : 'sdiv', [$lval, $rval], resultType: $operandType);
                    break;
                case '%':
                    $val = $this->builder->createInstruction($isFloat ? 'frem' : 'srem', [$lval, $rval], resultType: $operandType);
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
                case '===':
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp oeq' : 'icmp eq', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '!=':
                case '!==':
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp one' : 'icmp ne', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '<':
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp olt' : 'icmp slt', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '>':
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp ogt' : 'icmp sgt', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '<=':
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp ole' : 'icmp sle', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '>=':
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp oge' : 'icmp sge', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                default:
                    throw new \Exception("unknown BinaryOp {$sigil}");
            }
            return $val;
        } elseif ($expr instanceof \PhpParser\Node\Expr\UnaryMinus) {
            return $this->builder->createInstruction('sub', [new Constant(0, BaseType::INT), $this->buildExpr($expr->expr)]);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return new Constant($expr->value, BaseType::INT);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return new Constant($expr->value, BaseType::FLOAT);
        } elseif ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return $this->builder->createStringConstant($expr->value);
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
            if ($constName === 'null') {
                return new NullConstant();
            }
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
            assert($expr->name instanceof \PhpParser\Node\Name);
            $funcName = $expr->name->toLowerString();
            // Built-in functions
            if ($funcName === 'count') {
                assert(count($expr->args) === 1);
                assert($expr->args[0] instanceof \PhpParser\Node\Arg);
                $arrVal = $this->buildExpr($expr->args[0]->value);
                return $this->builder->createArrayLen($arrVal);
            }
            if ($funcName === 'strlen') {
                assert(count($expr->args) === 1);
                assert($expr->args[0] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                return $this->builder->createStringLen($strVal);
            }
            if ($funcName === 'str_starts_with') {
                assert(count($expr->args) === 2);
                assert($expr->args[0] instanceof \PhpParser\Node\Arg);
                assert($expr->args[1] instanceof \PhpParser\Node\Arg);
                $haystack = $this->buildExpr($expr->args[0]->value);
                $prefix = $this->buildExpr($expr->args[1]->value);
                $result = $this->builder->createCall('pico_string_starts_with', [$haystack, $prefix], BaseType::INT);
                return $this->builder->createInstruction('icmp ne', [$result, new Constant(0, BaseType::INT)], resultType: BaseType::BOOL);
            }
            if ($funcName === 'str_contains') {
                assert(count($expr->args) === 2);
                assert($expr->args[0] instanceof \PhpParser\Node\Arg);
                assert($expr->args[1] instanceof \PhpParser\Node\Arg);
                $haystack = $this->buildExpr($expr->args[0]->value);
                $needle = $this->buildExpr($expr->args[1]->value);
                $result = $this->builder->createCall('pico_string_contains', [$haystack, $needle], BaseType::INT);
                return $this->builder->createInstruction('icmp ne', [$result, new Constant(0, BaseType::INT)], resultType: BaseType::BOOL);
            }
            if ($funcName === 'substr') {
                assert(count($expr->args) >= 2 && count($expr->args) <= 3);
                assert($expr->args[0] instanceof \PhpParser\Node\Arg);
                assert($expr->args[1] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                $start = $this->buildExpr($expr->args[1]->value);
                $len = count($expr->args) === 3 && $expr->args[2] instanceof \PhpParser\Node\Arg
                    ? $this->buildExpr($expr->args[2]->value)
                    : new Constant(2147483647, BaseType::INT);
                return $this->builder->createCall('pico_string_substr', [$strVal, $start, $len], BaseType::STRING);
            }
            if ($funcName === 'trim') {
                assert(count($expr->args) === 1);
                assert($expr->args[0] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                return $this->builder->createCall('pico_string_trim', [$strVal], BaseType::STRING);
            }
            if ($funcName === 'str_repeat') {
                assert(count($expr->args) === 2);
                assert($expr->args[0] instanceof \PhpParser\Node\Arg);
                assert($expr->args[1] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                $times = $this->buildExpr($expr->args[1]->value);
                return $this->builder->createCall('pico_string_repeat', [$strVal, $times], BaseType::STRING);
            }
            $args = (new Collection($expr->args))
                ->map(function ($arg): ValueAbstract {
                    assert($arg instanceof \PhpParser\Node\Arg);
                    return $this->buildExpr($arg->value);
                })
                ->toArray();
            $funcSymbol = $pData->getSymbol();
            $returnType = $funcSymbol->type->toBase();
            /** @phpstan-ignore-next-line */
            return $this->builder->createCall($expr->name->name, $args, $returnType);
        } elseif ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            $varType = $this->getExprResolvedType($expr->var);
            if ($varType->isArray()) {
                // Array read: $arr[$idx] — writes handled in Assign
                assert($expr->dim !== null, "array read requires index");
                $arrPtr = $this->buildExpr($expr->var);
                $idx = $this->buildExpr($expr->dim);
                return $this->builder->createArrayGet($arrPtr, $idx, $varType->getElementBaseType());
            }
            $varData = PicoHPData::getPData($expr->var);
            // String indexing (existing behavior)
            assert($expr->dim !== null);
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
            $varPData = PicoHPData::getPData($expr->var);
            $ptr = $varPData->getValue();
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('add', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $oldVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\PostDec) {
            $varPData = PicoHPData::getPData($expr->var);
            $ptr = $varPData->getValue();
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('sub', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $oldVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\BooleanNot) {
            $val = $this->buildExpr($expr->expr);
            return $this->builder->createInstruction('xor', [$val, new Constant(1, BaseType::BOOL)], resultType: BaseType::BOOL);
        } elseif ($expr instanceof \PhpParser\Node\Expr\PreInc) {
            $varPData = PicoHPData::getPData($expr->var);
            $ptr = $varPData->getValue();
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('add', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $newVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\PreDec) {
            $varPData = PicoHPData::getPData($expr->var);
            $ptr = $varPData->getValue();
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('sub', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $newVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\New_) {
            assert($expr->class instanceof \PhpParser\Node\Name);
            $className = $expr->class->toString();
            $classMeta = $this->classRegistry[$className];
            $objPtr = $this->builder->createObjectAlloc($className);
            // Call constructor if it exists
            if (isset($classMeta->methods['__construct'])) {
                $args = (new Collection($expr->args))
                    ->map(function ($arg): ValueAbstract {
                        assert($arg instanceof \PhpParser\Node\Arg);
                        return $this->buildExpr($arg->value);
                    })
                    ->toArray();
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = array_merge([$objPtr], $args);
                $ctorOwner = $classMeta->methodOwner['__construct'] ?? $className;
                $qualifiedName = "{$ctorOwner}___construct";
                $this->builder->createCall($qualifiedName, $allArgs, BaseType::VOID);
            }
            return $objPtr;
        } elseif ($expr instanceof \PhpParser\Node\Expr\PropertyFetch) {
            assert($expr->name instanceof \PhpParser\Node\Identifier);
            $objVal = $this->buildExpr($expr->var);
            $varType = $this->getExprResolvedType($expr->var);
            $className = $varType->getClassName();
            $classMeta = $this->classRegistry[$className];
            $propName = $expr->name->toString();
            $fieldIndex = $classMeta->getPropertyIndex($propName);
            $fieldType = $classMeta->getPropertyType($propName)->toBase();
            $fieldPtr = $this->builder->createStructGEP($className, $objVal, $fieldIndex, $fieldType);
            if ($pData->lVal) {
                return $fieldPtr;
            }
            return $this->builder->createLoad($fieldPtr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\MethodCall) {
            assert($expr->name instanceof \PhpParser\Node\Identifier);
            $objVal = $this->buildExpr($expr->var);
            $varType = $this->getExprResolvedType($expr->var);
            $className = $varType->getClassName();
            $classMeta = $this->classRegistry[$className];
            $methodName = $expr->name->toString();
            $methodSymbol = $classMeta->methods[$methodName];
            $args = (new Collection($expr->args))
                ->map(function ($arg): ValueAbstract {
                    assert($arg instanceof \PhpParser\Node\Arg);
                    return $this->buildExpr($arg->value);
                })
                ->toArray();
            /** @var array<ValueAbstract> $allArgs */
            $allArgs = array_merge([$objVal], $args);
            $ownerClass = $classMeta->methodOwner[$methodName] ?? $className;
            $qualifiedName = "{$ownerClass}_{$methodName}";
            return $this->builder->createCall($qualifiedName, $allArgs, $methodSymbol->type->toBase());
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticCall) {
            assert($expr->class instanceof \PhpParser\Node\Name);
            assert($expr->name instanceof \PhpParser\Node\Identifier);
            $targetClass = $expr->class->toString();
            $methodName = $expr->name->toString();
            if ($targetClass === 'parent') {
                assert($this->currentClassName !== null);
                $classMeta = $this->classRegistry[$this->currentClassName];
                assert($classMeta->parentName !== null);
                $parentMeta = $this->classRegistry[$classMeta->parentName];
                $methodSymbol = $parentMeta->methods[$methodName];
                $ownerClass = $parentMeta->methodOwner[$methodName] ?? $classMeta->parentName;
                $targetClass = $ownerClass;
            } else {
                $classMeta = $this->classRegistry[$targetClass];
                $methodSymbol = $classMeta->methods[$methodName];
            }
            $args = (new Collection($expr->args))
                ->map(function ($arg): ValueAbstract {
                    assert($arg instanceof \PhpParser\Node\Arg);
                    return $this->buildExpr($arg->value);
                })
                ->toArray();
            // Pass $this as first argument for parent:: calls
            if ($expr->class->toString() === 'parent') {
                // Load $this from param 0 alloca
                assert($this->currentThisPtr !== null);
                $thisVal = $this->builder->createLoad($this->currentThisPtr);
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = array_merge([$thisVal], $args);
            } else {
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = $args;
            }
            $qualifiedName = "{$targetClass}_{$methodName}";
            return $this->builder->createCall($qualifiedName, $allArgs, $methodSymbol->type->toBase());
        } else {
            throw new \Exception("unknown node type in expr: " . get_class($expr));
        }
    }

    protected function getExprType(\PhpParser\Node\Expr $expr): \App\PicoHP\PicoType
    {
        $pData = PicoHPData::getPData($expr);
        return $pData->getSymbol()->type;
    }

    protected function getExprResolvedType(\PhpParser\Node\Expr $expr): \App\PicoHP\PicoType
    {
        if ($expr instanceof \PhpParser\Node\Expr\Variable) {
            return PicoHPData::getPData($expr)->getSymbol()->type;
        }
        if ($expr instanceof \PhpParser\Node\Expr\PropertyFetch) {
            assert($expr->name instanceof \PhpParser\Node\Identifier);
            $objType = $this->getExprResolvedType($expr->var);
            $classMeta = $this->classRegistry[$objType->getClassName()];
            return $classMeta->getPropertyType($expr->name->toString());
        }
        throw new \RuntimeException('getExprResolvedType: unsupported expr type ' . get_class($expr));
    }

    protected function buildShortCircuit(\PhpParser\Node\Expr\BinaryOp $expr, PicoHPData $pData): ValueAbstract
    {
        assert($this->currentFunction !== null);
        $isAnd = $expr->getOperatorSigil() === '&&';
        $count = $pData->mycount;

        $rhsBB = $this->currentFunction->addBasicBlock("sc_rhs{$count}");
        $endBB = $this->currentFunction->addBasicBlock("sc_end{$count}");
        $rhsLabel = new Label($rhsBB->getName());
        $endLabel = new Label($endBB->getName());

        $result = $this->builder->createAlloca("sc_result{$count}", BaseType::BOOL);
        $lval = $this->buildExpr($expr->left);
        $this->builder->createStore($lval, $result);

        if ($isAnd) {
            $this->builder->createBranch([$lval, $rhsLabel, $endLabel]);
        } else {
            $this->builder->createBranch([$lval, $endLabel, $rhsLabel]);
        }

        $this->builder->setInsertPoint($rhsBB);
        $rval = $this->buildExpr($expr->right);
        $this->builder->createStore($rval, $result);
        $this->builder->createBranch([$endLabel]);

        $this->builder->setInsertPoint($endBB);
        return $this->builder->createLoad($result);
    }

    protected function buildSymbolAlloca(\App\PicoHP\SymbolTable\Symbol $symbol): ValueAbstract
    {
        // Arrays are ptr slots (will hold pico_array_new() result)
        return $this->builder->createAlloca($symbol->name, $symbol->type->toBase());
    }

    protected function buildArrayInit(\PhpParser\Node\Expr\Array_ $arrayExpr, \App\PicoHP\PicoType $arrayType): ValueAbstract
    {
        $arrPtr = $this->builder->createArrayNew();
        $elementType = $arrayType->getElementBaseType();
        foreach ($arrayExpr->items as $item) {
            $elemVal = $this->buildExpr($item->value);
            $this->builder->createArrayPush($arrPtr, $elemVal, $elementType);
        }
        return $arrPtr;
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
