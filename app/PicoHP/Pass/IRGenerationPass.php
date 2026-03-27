<?php

declare(strict_types=1);

namespace App\PicoHP\Pass;

use App\PicoHP\{BaseType};
use App\PicoHP\LLVM\{Module, Builder, ValueAbstract, IRLine};
use App\PicoHP\LLVM\Value\{Constant, Void_, Label, Param, NullConstant};
use App\PicoHP\SymbolTable\{ClassMetadata, EnumMetadata, PicoHPData};
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

    /** @var array<string, EnumMetadata> */
    protected array $enumRegistry = [];

    /** @var array<string, int> class name => type_id */
    protected array $typeIdMap = [];

    protected int $vdispatchCount = 0;

    protected function isDestructuringAssign(\PhpParser\Node\Expr\Assign $expr): bool
    {
        if (!($expr->var instanceof \PhpParser\Node\Expr\Array_)) {
            return false;
        }
        // If the LHS array items are variables, it's destructuring
        foreach ($expr->var->items as $item) {
            /** @phpstan-ignore-next-line — items can be null for skipped positions */
            if ($item !== null && $item->value instanceof \PhpParser\Node\Expr\Variable) {
                return true;
            }
        }
        return false;
    }

    /** @var array<string> stack of break target block names */
    protected array $breakTargets = [];

    /**
     * @param array<\PhpParser\Node> $stmts
     * @param array<string, ClassMetadata> $classRegistry
     * @param array<string, EnumMetadata> $enumRegistry
     * @param array<string, int> $typeIdMap
     */
    public function __construct(array $stmts, array $classRegistry = [], array $enumRegistry = [], array $typeIdMap = [])
    {
        $this->module = new Module("test_module");
        $this->builder = $this->module->getBuilder();
        $this->stmts = $stmts;
        $this->classRegistry = $classRegistry;
        $this->enumRegistry = $enumRegistry;
        $this->typeIdMap = $typeIdMap;
    }

    public function exec(): void
    {
        $this->emitBuiltinExceptionClass();
        $this->buildStmts($this->stmts);
    }

    protected function emitBuiltinExceptionClass(): void
    {
        if (!isset($this->classRegistry['Exception'])) {
            return;
        }
        // Exception struct: { ptr message }
        $this->module->addLine(new \App\PicoHP\LLVM\IRLine('%struct.Exception = type { ptr }'));

        // Exception___construct(ptr %this, ptr %message)
        $ctorFunc = $this->module->addFunction('Exception___construct', new \App\PicoHP\PicoType(BaseType::VOID), [
            new \App\PicoHP\PicoType(BaseType::PTR),
            new \App\PicoHP\PicoType(BaseType::STRING),
        ]);
        $bb = $ctorFunc->addBasicBlock('entry');
        $this->builder->setInsertPoint($bb);
        $thisParam = new Param(0, BaseType::PTR);
        $msgParam = new Param(1, BaseType::STRING);
        $fieldPtr = $this->builder->createStructGEP('Exception', $thisParam, 0, BaseType::STRING);
        $this->builder->createStore($msgParam, $fieldPtr);
        $this->builder->createRetVoid();

        // Exception_getMessage(ptr %this) -> ptr
        $getMessageFunc = $this->module->addFunction('Exception_getMessage', new \App\PicoHP\PicoType(BaseType::STRING), [
            new \App\PicoHP\PicoType(BaseType::PTR),
        ]);
        $bb = $getMessageFunc->addBasicBlock('entry');
        $this->builder->setInsertPoint($bb);
        $thisParam = new Param(0, BaseType::PTR);
        $fieldPtr = $this->builder->createStructGEP('Exception', $thisParam, 0, BaseType::STRING);
        $msgVal = $this->builder->createLoad($fieldPtr);
        $this->builder->createInstruction('ret', [$msgVal], false);
    }

    /**
     * @param array<\PhpParser\Node> $stmts
     */
    public function buildStmts(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            \App\PicoHP\CompilerInvariant::check($stmt instanceof \PhpParser\Node\Stmt);
            $this->buildStmt($stmt);
        }
    }

    public function buildStmt(\PhpParser\Node\Stmt $stmt): void
    {
        $pData = PicoHPData::getPData($stmt);

        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            $funcSymbol = $pData->getSymbol();
            \App\PicoHP\CompilerInvariant::check($funcSymbol->func === true);
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
                    \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
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
            \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
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
            \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
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
            \App\PicoHP\CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
            $className = $stmt->name->toString();
            \App\PicoHP\CompilerInvariant::check(isset($this->classRegistry[$className]));
            $classMeta = $this->classRegistry[$className];
            $fields = $classMeta->toLLVMStructFields();
            if ($fields !== '') {
                $this->module->addLine(new IRLine("%struct.{$className} = type { {$fields} }"));
            } else {
                $this->module->addLine(new IRLine("%struct.{$className} = type {}"));
            }
            // Emit static properties as globals
            foreach ($classMeta->staticProperties as $propName => $propType) {
                $llvmType = $propType->toBase()->toLLVM();
                $default = $classMeta->staticDefaults[$propName] ?? null;
                $initVal = '0';
                if ($default instanceof \PhpParser\Node\Scalar\Int_) {
                    $initVal = (string) $default->value;
                } elseif ($default instanceof \PhpParser\Node\Scalar\Float_) {
                    $initVal = sprintf('%e', $default->value);
                } elseif ($default instanceof \PhpParser\Node\Scalar\String_) {
                    $initVal = 'null'; // string pointers default to null
                } elseif ($default instanceof \PhpParser\Node\Expr\ConstFetch) {
                    $name = $default->name->toLowerString();
                    if ($name === 'true') {
                        $initVal = '1';
                    } elseif ($name === 'false' || $name === 'null') {
                        $initVal = '0';
                    }
                }
                $this->module->addLine(new IRLine("@{$className}_{$propName} = global {$llvmType} {$initVal}"));
            }
            $this->currentClassName = $className;
            $this->buildStmts($stmt->stmts);
            $this->currentClassName = null;
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
            // Handled by struct type definition
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
            // Abstract methods have no body — skip IR generation
            if ($stmt->stmts === null) {
                return;
            }
            \App\PicoHP\CompilerInvariant::check($this->currentClassName !== null);
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
            \App\PicoHP\CompilerInvariant::check($thisSymbol !== null);
            \App\PicoHP\CompilerInvariant::check($thisSymbol->value !== null);
            $this->builder->createStore(new Param(0, \App\PicoHP\BaseType::PTR), $thisSymbol->value);
            $this->currentThisPtr = $thisSymbol->value;
            // Store remaining params (offset by 1)
            $paramIndex = 1;
            foreach ($stmt->params as $param) {
                $paramPData = PicoHPData::getPData($param);
                $type = $paramPData->getSymbol()->type;
                $this->builder->createStore(new Param($paramIndex++, $type->toBase()), $paramPData->getValue());
            }
            $this->buildStmts($stmt->stmts);
            if ($funcSymbol->type->toBase() === \App\PicoHP\BaseType::VOID) {
                $this->builder->createRetVoid();
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Do_) {
            \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
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
            \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
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
            \App\PicoHP\CompilerInvariant::check(count($conds) > 0);
            $this->builder->createBranch([$conds[0], $bodyLabel, $endLabel]);
            $this->builder->setInsertPoint($bodyBB);
            $this->buildStmts($stmt->stmts);
            foreach ($stmt->loop as $loop) {
                $this->buildExpr($loop);
            }
            $this->builder->createBranch([$condLabel]);
            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Switch_) {
            \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
            $count = $pData->mycount;
            $condVal = $this->buildExpr($stmt->cond);

            $endBB = $this->currentFunction->addBasicBlock("switch_end{$count}");
            $this->breakTargets[] = $endBB->getName();

            // Build case blocks and collect switch cases
            $caseBBs = [];
            $defaultBB = null;
            foreach ($stmt->cases as $i => $case) {
                $bb = $this->currentFunction->addBasicBlock("switch_case{$count}_{$i}");
                $caseBBs[] = $bb;
                if ($case->cond === null) {
                    $defaultBB = $bb;
                }
            }

            // Build LLVM switch instruction
            $switchCases = [];
            foreach ($stmt->cases as $i => $case) {
                if ($case->cond !== null) {
                    $caseVal = $this->buildExpr($case->cond);
                    $switchCases[] = "{$condVal->getType()->toLLVM()} {$caseVal->render()}, label %{$caseBBs[$i]->getName()}";
                }
            }
            $defaultLabel = $defaultBB !== null ? $defaultBB->getName() : $endBB->getName();
            $casesStr = implode(' ', $switchCases);
            $this->builder->addLine("switch {$condVal->getType()->toLLVM()} {$condVal->render()}, label %{$defaultLabel} [{$casesStr}]", 1);

            // Emit case bodies with fallthrough
            foreach ($stmt->cases as $i => $case) {
                $this->builder->setInsertPoint($caseBBs[$i]);
                if (count($case->stmts) === 0) {
                    // Empty case = fallthrough to next
                    $nextBB = isset($caseBBs[$i + 1]) ? $caseBBs[$i + 1] : $endBB;
                    $this->builder->createBranch([new Label($nextBB->getName())]);
                } else {
                    $this->buildStmts($case->stmts);
                }
            }

            array_pop($this->breakTargets);
            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Break_) {
            \App\PicoHP\CompilerInvariant::check(count($this->breakTargets) > 0, 'break outside of switch/loop');
            $target = end($this->breakTargets);
            $this->builder->createBranch([new Label($target)]);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Foreach_) {
            \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
            $count = $pData->mycount;

            $arrayPtr = $this->buildExpr($stmt->expr);
            $arrayType = $this->getExprResolvedType($stmt->expr);
            $elemBaseType = $arrayType->isMixed() ? BaseType::PTR : $arrayType->getElementBaseType();

            \App\PicoHP\CompilerInvariant::check($stmt->valueVar instanceof \PhpParser\Node\Expr\Variable);
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
            if ($arrayType->isArray() && $arrayType->hasStringKeys()) {
                $lenVal = $this->builder->createCall('pico_map_len', [$arrayPtr], BaseType::INT);
            } else {
                $lenVal = $this->builder->createArrayLen($arrayPtr);
            }
            $cond = $this->builder->createInstruction('icmp slt', [$idx, $lenVal], resultType: BaseType::BOOL);
            $this->builder->createBranch([$cond, $bodyLabel, $endLabel]);

            $this->builder->setInsertPoint($bodyBB);
            if ($arrayType->isArray() && $arrayType->hasStringKeys()) {
                $getValueFunc = 'pico_map_get_value_' . match ($elemBaseType) {
                    BaseType::INT => 'int',
                    BaseType::STRING => 'str',
                    default => throw new \RuntimeException("unsupported map foreach value type"),
                };
                $elemVal = $this->builder->createCall($getValueFunc, [$arrayPtr, $idx], $elemBaseType);
            } else {
                $elemVal = $this->builder->createArrayGet($arrayPtr, $idx, $elemBaseType);
            }
            $this->builder->createStore($elemVal, $valuePtr);
            if ($stmt->keyVar !== null) {
                \App\PicoHP\CompilerInvariant::check($stmt->keyVar instanceof \PhpParser\Node\Expr\Variable);
                $keyVarPData = PicoHPData::getPData($stmt->keyVar);
                $keyPtr = $keyVarPData->getValue();
                if ($arrayType->hasStringKeys()) {
                    $keyVal = $this->builder->createCall('pico_map_get_key', [$arrayPtr, $idx], BaseType::STRING);
                    $this->builder->createStore($keyVal, $keyPtr);
                } else {
                    $this->builder->createStore($idx, $keyPtr);
                }
            }
            $this->buildStmts($stmt->stmts);
            $idxNext = $this->builder->createInstruction('add', [$idx, new Constant(1, BaseType::INT)]);
            $this->builder->createStore($idxNext, $counterPtr);
            $this->builder->createBranch([$condLabel]);

            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Interface_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Trait_) {
            // Traits are inlined into classes at semantic analysis time; nothing to emit
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Enum_) {
            \App\PicoHP\CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
            $enumName = $stmt->name->toString();
            $enumMeta = $this->enumRegistry[$enumName];
            // Emit backing value lookup table as a global array of ptrs
            if ($enumMeta->backingType === 'string') {
                $ptrs = [];
                foreach ($enumMeta->cases as $caseName => $tag) {
                    $value = $enumMeta->backingValues[$caseName];
                    \App\PicoHP\CompilerInvariant::check(is_string($value));
                    $constVal = $this->builder->createStringConstant($value);
                    $ptrs[] = "ptr {$constVal->render()}";
                }
                $count = count($ptrs);
                $init = implode(', ', $ptrs);
                $this->module->addLine(new IRLine("@{$enumName}_values = global [{$count} x ptr] [{$init}]"));
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\ClassConst) {
            // Class constants — values resolved at compile time via ClassConstFetch
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\EnumCase) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Use_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
            $this->buildStmts($stmt->stmts);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\TryCatch) {
            $this->buildTryCatch($stmt, $pData);
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
            // List/array destructuring: [$a, $b] = $arr
            if ($expr->var instanceof \PhpParser\Node\Expr\List_
                || ($expr->var instanceof \PhpParser\Node\Expr\Array_ && $this->isDestructuringAssign($expr))) {
                $arrVal = $this->buildExpr($expr->expr);
                $arrType = $this->getExprResolvedType($expr->expr);
                $items = $expr->var instanceof \PhpParser\Node\Expr\List_
                    ? $expr->var->items
                    : $expr->var->items;
                foreach ($items as $i => $item) {
                    /** @phpstan-ignore-next-line — items can be null for skipped positions */
                    if ($item !== null && $item->value !== null) {
                        $lval = $this->buildExpr($item->value);
                        $elemType = $arrType->isArray() ? $arrType->getElementBaseType() : BaseType::PTR;
                        $elemVal = $this->builder->createArrayGet($arrVal, new Constant($i, BaseType::INT), $elemType);
                        $this->builder->createStore($elemVal, $lval);
                    }
                }
                return $arrVal;
            }
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
                $arrVarExpr = $expr->var->var;
                $arrPtrPtr = $this->buildExpr($arrVarExpr);
                $arrPtr = $this->builder->createLoad($arrPtrPtr);
                $arrayType = $this->getExprResolvedType($arrVarExpr);
                $elemBaseType = $arrayType->isMixed() ? BaseType::PTR : $arrayType->getElementBaseType();
                if ($expr->var->dim === null) {
                    // $arr[] = val (push)
                    $this->builder->createArrayPush($arrPtr, $rval, $elemBaseType);
                } else {
                    // $arr[idx] = val (set). If idx is a STRING, treat this as a map assignment.
                    $keyOrIdxVal = $this->buildExpr($expr->var->dim);
                    $shouldUseMapSet = ($arrayType->isArray() && $arrayType->hasStringKeys())
                        || $keyOrIdxVal->getType() === BaseType::STRING;

                    if ($shouldUseMapSet) {
                        $setFunc = 'pico_map_set_' . match ($elemBaseType) {
                            BaseType::INT => 'int',
                            BaseType::FLOAT => 'float',
                            BaseType::BOOL => 'bool',
                            BaseType::STRING => 'str',
                            BaseType::PTR => 'ptr',
                            default => throw new \RuntimeException("unsupported map set type"),
                        };
                        $this->builder->createCall($setFunc, [$arrPtr, $keyOrIdxVal, $rval], BaseType::VOID);
                    } else {
                        $this->builder->createArraySet($arrPtr, $keyOrIdxVal, $rval, $elemBaseType);
                    }
                }
                return $rval;
            }
            $lval = $this->buildExpr($expr->var);
            $rval = $this->buildExpr($expr->expr);
            // Void methods used as values (e.g. $mixed = $this->voidMethod()) → null ptr.
            // Only safe when LHS is a ptr/mixed slot; would be incorrect for typed non-ptr locals.
            if ($rval instanceof Void_) {
                $rval = new NullConstant();
            }
            $this->builder->createStore($rval, $lval);
            return $rval;
        } elseif ($expr instanceof \PhpParser\Node\Expr\AssignOp\Plus) {
            return $this->buildCompoundAssign($expr, 'add', 'fadd');
        } elseif ($expr instanceof \PhpParser\Node\Expr\AssignOp\Minus) {
            return $this->buildCompoundAssign($expr, 'sub', 'fsub');
        } elseif ($expr instanceof \PhpParser\Node\Expr\Variable) {
            if (is_string($expr->name) && $expr->name === 'this') {
                \App\PicoHP\CompilerInvariant::check(
                    $this->currentThisPtr !== null,
                    "line {$expr->getStartLine()}, \$this is unavailable outside class method context"
                );
                if ($pData->lVal) {
                    return $this->currentThisPtr;
                }
                return $this->builder->createLoad($this->currentThisPtr);
            }
            $varName = is_string($expr->name) ? $expr->name : get_debug_type($expr->name);
            \App\PicoHP\CompilerInvariant::check(
                $pData->symbol !== null && $pData->symbol->value !== null,
                "line {$expr->getStartLine()}, variable \${$varName} has no allocated IR value"
            );
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

            // For mixed-backed boxed-int values, treat ptr/string as integer bits before integer ops.
            $intOpSigils = ['|', '&', '^', '<<', '>>', '+', '-', '*', '/', '%', '<', '>', '<=', '>='];
            if (($lval->getType() === BaseType::PTR || $lval->getType() === BaseType::STRING) && in_array($sigil, $intOpSigils, true)) {
                $lval = $this->builder->createPtrToInt($lval);
            }
            if (($rval->getType() === BaseType::PTR || $rval->getType() === BaseType::STRING) && in_array($sigil, $intOpSigils, true)) {
                $rval = $this->builder->createPtrToInt($rval);
            }

            // Different types with === / !== — result is known at compile time
            if ($lval->getType() !== $rval->getType() && ($sigil === '===' || $sigil === '!==')) {
                return new Constant($sigil === '!==' ? 1 : 0, BaseType::BOOL);
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
                    if ($operandType === BaseType::STRING
                        && !($lval instanceof NullConstant)
                        && !($rval instanceof NullConstant)
                    ) {
                        $result = $this->builder->createCall('pico_string_eq', [$lval, $rval], BaseType::INT);
                        $val = $this->builder->createInstruction(
                            'icmp ne',
                            [$result, new Constant(0, BaseType::INT)],
                            resultType: BaseType::BOOL,
                        );
                        break;
                    }
                    $val = $this->builder->createInstruction($isFloat ? 'fcmp oeq' : 'icmp eq', [$lval, $rval], resultType: BaseType::BOOL);
                    break;
                case '!=':
                case '!==':
                    if ($operandType === BaseType::STRING
                        && !($lval instanceof NullConstant)
                        && !($rval instanceof NullConstant)
                    ) {
                        $result = $this->builder->createCall('pico_string_ne', [$lval, $rval], BaseType::INT);
                        $val = $this->builder->createInstruction(
                            'icmp ne',
                            [$result, new Constant(0, BaseType::INT)],
                            resultType: BaseType::BOOL,
                        );
                        break;
                    }
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
        } elseif ($expr instanceof \PhpParser\Node\Expr\ClassConstFetch) {
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $className = $expr->class->toString();
            $caseName = $expr->name->toString();
            if (isset($this->enumRegistry[$className])) {
                $tag = $this->enumRegistry[$className]->getCaseTag($caseName);
                return new Constant($tag, BaseType::INT);
            }
            if (isset($this->classRegistry[$className])) {
                if (isset($this->classRegistry[$className]->constants[$caseName])) {
                    return new Constant($this->classRegistry[$className]->constants[$caseName], BaseType::INT);
                }
                // Unknown constant on a known class — return 0 (stub behavior)
                return new Constant(0, BaseType::INT);
            }
            throw new \RuntimeException("class constant {$className}::{$caseName} not supported");
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
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\Bool_) {
            $val = $this->buildExpr($expr->expr);

            switch ($val->getType()) {
                case BaseType::INT:
                    return $this->builder->createInstruction('icmp ne', [$val, new Constant(0, BaseType::INT)], resultType: BaseType::BOOL);
                case BaseType::FLOAT:
                    return $this->builder->createInstruction('fcmp one', [$val, new Constant(0.0, BaseType::FLOAT)], resultType: BaseType::BOOL);
                case BaseType::BOOL:
                    return $val;
                default:
                    throw new \Exception("casting to bool from unknown type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\Cast\String_) {
            $val = $this->buildExpr($expr->expr);

            switch ($val->getType()) {
                case BaseType::INT:
                    return $this->builder->createCall('pico_int_to_string', [$val], BaseType::STRING);
                case BaseType::FLOAT:
                    return $this->builder->createCall('pico_float_to_string', [$val], BaseType::STRING);
                case BaseType::STRING:
                    return $val;
                default:
                    throw new \Exception("casting to string from unsupported type");
            }
        } elseif ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Name);
            $funcName = $expr->name->toLowerString();
            // Built-in functions
            if ($funcName === 'assert') {
                // Compile assert as no-op (assertions stripped in compiled code)
                return new Void_();
            }
            if ($funcName === 'count') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $arrVal = $this->buildExpr($expr->args[0]->value);
                return $this->builder->createArrayLen($arrVal);
            }
            if ($funcName === 'strval') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $val = $this->buildExpr($expr->args[0]->value);
                if ($val->getType() === BaseType::FLOAT) {
                    return $this->builder->createCall('pico_float_to_string', [$val], BaseType::STRING);
                }
                return $this->builder->createCall('pico_int_to_string', [$val], BaseType::STRING);
            }
            if ($funcName === 'strlen') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                return $this->builder->createStringLen($strVal);
            }
            if ($funcName === 'str_starts_with') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 2);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $haystack = $this->buildExpr($expr->args[0]->value);
                $prefix = $this->buildExpr($expr->args[1]->value);
                $result = $this->builder->createCall('pico_string_starts_with', [$haystack, $prefix], BaseType::INT);
                return $this->builder->createInstruction('icmp ne', [$result, new Constant(0, BaseType::INT)], resultType: BaseType::BOOL);
            }
            if ($funcName === 'str_contains') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 2);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $haystack = $this->buildExpr($expr->args[0]->value);
                $needle = $this->buildExpr($expr->args[1]->value);
                $result = $this->builder->createCall('pico_string_contains', [$haystack, $needle], BaseType::INT);
                return $this->builder->createInstruction('icmp ne', [$result, new Constant(0, BaseType::INT)], resultType: BaseType::BOOL);
            }
            if ($funcName === 'substr') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) >= 2 && count($expr->args) <= 3);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                $start = $this->buildExpr($expr->args[1]->value);
                $len = count($expr->args) === 3 && $expr->args[2] instanceof \PhpParser\Node\Arg
                    ? $this->buildExpr($expr->args[2]->value)
                    : new Constant(2147483647, BaseType::INT);
                return $this->builder->createCall('pico_string_substr', [$strVal, $start, $len], BaseType::STRING);
            }
            if ($funcName === 'trim') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                return $this->builder->createCall('pico_string_trim', [$strVal], BaseType::STRING);
            }
            if ($funcName === 'str_replace') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 3);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[2] instanceof \PhpParser\Node\Arg);
                $search = $this->buildExpr($expr->args[0]->value);
                $replace = $this->buildExpr($expr->args[1]->value);
                $subject = $this->buildExpr($expr->args[2]->value);
                return $this->builder->createCall('pico_string_replace', [$search, $replace, $subject], BaseType::STRING);
            }
            if ($funcName === 'str_repeat') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 2);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                $times = $this->buildExpr($expr->args[1]->value);
                return $this->builder->createCall('pico_string_repeat', [$strVal, $times], BaseType::STRING);
            }
            if ($funcName === 'strtoupper') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                return $this->builder->createCall('pico_string_upper', [$strVal], BaseType::STRING);
            }
            if ($funcName === 'strtolower') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                return $this->builder->createCall('pico_string_lower', [$strVal], BaseType::STRING);
            }
            if ($funcName === 'dechex') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $val = $this->buildExpr($expr->args[0]->value);
                return $this->builder->createCall('pico_dechex', [$val], BaseType::STRING);
            }
            if ($funcName === 'str_pad') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) >= 2);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $strVal = $this->buildExpr($expr->args[0]->value);
                $length = $this->buildExpr($expr->args[1]->value);
                $padStr = (count($expr->args) >= 3 && $expr->args[2] instanceof \PhpParser\Node\Arg)
                    ? $this->buildExpr($expr->args[2]->value)
                    : $this->builder->createStringConstant(' ');
                // STR_PAD_RIGHT = 1 (default), STR_PAD_LEFT = 0
                $padType = (count($expr->args) >= 4 && $expr->args[3] instanceof \PhpParser\Node\Arg)
                    ? $this->buildExpr($expr->args[3]->value)
                    : new Constant(1, BaseType::INT);
                return $this->builder->createCall('pico_string_pad', [$strVal, $length, $padStr, $padType], BaseType::STRING);
            }
            if ($funcName === 'implode') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 2);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $glue = $this->buildExpr($expr->args[0]->value);
                $arr = $this->buildExpr($expr->args[1]->value);
                return $this->builder->createCall('pico_implode', [$glue, $arr], BaseType::STRING);
            }
            if ($funcName === 'array_key_exists') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 2);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $key = $this->buildExpr($expr->args[0]->value);
                $map = $this->buildExpr($expr->args[1]->value);
                return $this->builder->createCall('pico_map_has_key', [$map, $key], BaseType::BOOL);
            }
            if ($funcName === 'array_reverse') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) >= 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                return $this->buildExpr($expr->args[0]->value);
            }
            if ($funcName === 'array_pop') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $arrPtr = $this->buildExpr($expr->args[0]->value);
                $len = $this->builder->createArrayLen($arrPtr);
                $lastIdx = $this->builder->createInstruction('sub', [$len, new Constant(1, BaseType::INT)]);
                $this->builder->createCall('pico_array_splice', [$arrPtr, $lastIdx, new Constant(1, BaseType::INT)], BaseType::VOID);
                return new Void_();
            }
            if ($funcName === 'array_merge') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) >= 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                return $this->buildExpr($expr->args[0]->value);
            }
            if ($funcName === 'array_search') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) >= 2);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $needle = $this->buildExpr($expr->args[0]->value);
                $haystack = $this->buildExpr($expr->args[1]->value);
                return $this->builder->createCall('pico_array_search_int', [$haystack, $needle], BaseType::INT);
            }
            if ($funcName === 'array_splice') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) >= 3);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[2] instanceof \PhpParser\Node\Arg);
                $arrPtr = $this->buildExpr($expr->args[0]->value);
                $offset = $this->buildExpr($expr->args[1]->value);
                $length = $this->buildExpr($expr->args[2]->value);
                $this->builder->createCall('pico_array_splice', [$arrPtr, $offset, $length], BaseType::VOID);
                return new Void_();
            }
            if ($funcName === 'end') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $arrPtr = $this->buildExpr($expr->args[0]->value);
                $arrType = $this->getExprResolvedType($expr->args[0]->value);
                if ($arrType->getElementBaseType() === BaseType::STRING) {
                    return $this->builder->createCall('pico_array_last_str', [$arrPtr], BaseType::STRING);
                }
                return $this->builder->createCall('pico_array_last_int', [$arrPtr], BaseType::INT);
            }
            if ($funcName === 'preg_match') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) >= 2);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                \App\PicoHP\CompilerInvariant::check($expr->args[1] instanceof \PhpParser\Node\Arg);
                $pattern = $this->buildExpr($expr->args[0]->value);
                $subject = $this->buildExpr($expr->args[1]->value);
                if (count($expr->args) >= 3 && $expr->args[2] instanceof \PhpParser\Node\Arg) {
                    // 3rd arg is by-reference matches array — load the array ptr
                    $matchesPtr = $this->buildExpr($expr->args[2]->value);
                    return $this->builder->createCall('pico_preg_match', [$pattern, $subject, $matchesPtr], BaseType::INT);
                }
                // No matches arg — create a temp array and discard
                $tmpArr = $this->builder->createArrayNew();
                return $this->builder->createCall('pico_preg_match', [$pattern, $subject, $tmpArr], BaseType::INT);
            }
            if ($funcName === 'is_int' || $funcName === 'is_string' || $funcName === 'is_float' || $funcName === 'is_bool') {
                // At compile time we know the type
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $val = $this->buildExpr($expr->args[0]->value);
                $expected = match ($funcName) {
                    'is_int' => BaseType::INT,
                    'is_string' => BaseType::STRING,
                    'is_float' => BaseType::FLOAT,
                    'is_bool' => BaseType::BOOL,
                };
                return new Constant($val->getType() === $expected ? 1 : 0, BaseType::BOOL);
            }
            if ($funcName === 'intval') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) === 1);
                \App\PicoHP\CompilerInvariant::check($expr->args[0] instanceof \PhpParser\Node\Arg);
                $val = $this->buildExpr($expr->args[0]->value);
                if ($val->getType() === BaseType::FLOAT) {
                    return $this->builder->createFpToSi($val);
                }
                return $val;
            }
            $funcSymbol = $pData->getSymbol();
            $args = $this->buildArgsWithDefaults($expr->args, $funcSymbol);
            $returnType = $funcSymbol->type->toBase();
            return $this->builder->createCall($expr->name->name, $args, $returnType);
        } elseif ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            $varType = $this->getExprResolvedType($expr->var);
            if ($varType->isArray() || $varType->isMixed()) {
                \App\PicoHP\CompilerInvariant::check($expr->dim !== null, "array read requires index");
                $arrPtr = $this->buildExpr($expr->var);
                $idx = $this->buildExpr($expr->dim);
                $elemBaseType = $varType->isMixed() ? BaseType::PTR : $varType->getElementBaseType();
                if ($varType->isArray() && $varType->hasStringKeys()) {
                    $getFunc = 'pico_map_get_' . match ($elemBaseType) {
                        BaseType::INT => 'int',
                        BaseType::FLOAT => 'float',
                        BaseType::BOOL => 'bool',
                        BaseType::STRING => 'str',
                        BaseType::PTR => 'ptr',
                        default => throw new \RuntimeException("unsupported map get type"),
                    };
                    return $this->builder->createCall($getFunc, [$arrPtr, $idx], $elemBaseType);
                }
                return $this->builder->createArrayGet($arrPtr, $idx, $elemBaseType);
            }
            // String indexing (existing behavior)
            \App\PicoHP\CompilerInvariant::check($expr->dim !== null);
            \App\PicoHP\CompilerInvariant::check(
                $pData->lVal !== true,
                "line {$expr->getStartLine()}, string index assignment is not supported"
            );
            $strVal = $this->buildExpr($expr->var);
            $idx = $this->buildExpr($expr->dim);
            return $this->builder->createStringByteAt($strVal, $idx);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Include_) {
            return new Void_();
        } elseif ($expr instanceof \PhpParser\Node\Expr\PostInc) {
            $ptr = $this->resolveVarPtr($expr->var);
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('add', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $oldVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\PostDec) {
            $ptr = $this->resolveVarPtr($expr->var);
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('sub', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $oldVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\BooleanNot) {
            $val = $this->buildExpr($expr->expr);
            // For ptr/mixed values, !$val means $val == null (i.e. falsy)
            if ($val->getType() === BaseType::PTR || $val->getType() === BaseType::STRING) {
                return $this->builder->createInstruction('icmp eq', [$val, new \App\PicoHP\LLVM\Value\NullConstant()], resultType: BaseType::BOOL);
            }
            return $this->builder->createInstruction('xor', [$val, new Constant(1, BaseType::BOOL)], resultType: BaseType::BOOL);
        } elseif ($expr instanceof \PhpParser\Node\Expr\PreInc) {
            $ptr = $this->resolveVarPtr($expr->var);
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('add', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $newVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\PreDec) {
            $ptr = $this->resolveVarPtr($expr->var);
            $oldVal = $this->builder->createLoad($ptr);
            $newVal = $this->builder->createInstruction('sub', [$oldVal, new Constant(1, $oldVal->getType())]);
            $this->builder->createStore($newVal, $ptr);
            return $newVal;
        } elseif ($expr instanceof \PhpParser\Node\Expr\New_) {
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            $className = $expr->class->toString();
            $classMeta = $this->classRegistry[$className];
            $typeId = $this->typeIdMap[$className] ?? 0;
            $objPtr = $this->builder->createObjectAlloc($className, $typeId);
            // Store type_id in field 0
            $typeIdPtr = $this->builder->createStructGEP($className, $objPtr, 0, BaseType::INT);
            $this->builder->createStore(new Constant($typeId, BaseType::INT), $typeIdPtr);
            // Emit property default values before constructor
            $this->emitPropertyDefaults($className, $classMeta, $objPtr);
            // Call constructor if it exists
            if (isset($classMeta->methods['__construct'])) {
                $ctorSymbol = $classMeta->methods['__construct'];
                $args = $this->buildArgsWithDefaults($expr->args, $ctorSymbol);
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = array_merge([$objPtr], $args);
                $ctorOwner = $classMeta->methodOwner['__construct'] ?? $className;
                $qualifiedName = "{$ctorOwner}___construct";
                $this->builder->createCall($qualifiedName, $allArgs, BaseType::VOID);
            }
            return $objPtr;
        } elseif ($expr instanceof \PhpParser\Node\Expr\PropertyFetch) {
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $objVal = $this->buildExpr($expr->var);
            $varType = $this->getExprResolvedType($expr->var);
            // Mixed type: no static class name, emit null ptr (stub behavior)
            if ($varType->isMixed()) {
                return new NullConstant();
            }
            // Enum ->value access
            if ($varType->isEnum() && $expr->name->toString() === 'value') {
                $enumName = $varType->getClassName();
                $enumMeta = $this->enumRegistry[$enumName];
                if ($enumMeta->backingType === 'string') {
                    $elemPtr = $this->builder->createEnumValueLookup($enumName, count($enumMeta->cases), $objVal);
                    return $this->builder->createLoad($elemPtr);
                }
                return $objVal; // int-backed: tag IS the value
            }
            $className = $varType->getClassName();
            $classMeta = $this->classRegistry[$className];
            $propName = $expr->name->toString();
            // Virtual dispatch when the property isn't on this class (interface or abstract base)
            if (!isset($classMeta->properties[$propName])) {
                return $this->emitVirtualPropertyDispatch($objVal, $className, $propName, $pData->lVal);
            }
            $fieldIndex = $classMeta->getPropertyIndex($propName);
            $fieldType = $classMeta->getPropertyType($propName)->toBase();
            $fieldPtr = $this->builder->createStructGEP($className, $objVal, $fieldIndex, $fieldType);
            if ($pData->lVal) {
                return $fieldPtr;
            }
            return $this->builder->createLoad($fieldPtr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\MethodCall) {
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $objVal = $this->buildExpr($expr->var);
            $varType = $this->getExprResolvedType($expr->var);
            // Mixed type: no static class name, emit null ptr (stub behavior)
            if ($varType->isMixed()) {
                // Still need to build args for side effects
                foreach ($expr->args as $arg) {
                    \App\PicoHP\CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
                    $this->buildExpr($arg->value);
                }
                return new NullConstant();
            }
            $className = $varType->getClassName();
            $classMeta = $this->classRegistry[$className];
            $methodName = $expr->name->toString();
            $methodSymbol = $classMeta->methods[$methodName];
            $args = $this->buildArgsWithDefaults($expr->args, $methodSymbol);
            /** @var array<ValueAbstract> $allArgs */
            $allArgs = array_merge([$objVal], $args);
            $returnType = $methodSymbol->type->toBase();

            // Virtual dispatch: interface (no type_id) or abstract method
            if (!isset($this->typeIdMap[$className]) || $this->needsVirtualDispatch($className, $methodName)) {
                return $this->emitVirtualDispatch($objVal, $className, $methodName, $allArgs, $returnType);
            }

            $ownerClass = $classMeta->methodOwner[$methodName] ?? $className;
            $qualifiedName = "{$ownerClass}_{$methodName}";
            return $this->builder->createCall($qualifiedName, $allArgs, $returnType);
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticCall) {
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $targetClass = $expr->class->toString();
            $methodName = $expr->name->toString();
            if ($targetClass === 'self') {
                \App\PicoHP\CompilerInvariant::check($this->currentClassName !== null);
                $targetClass = $this->currentClassName;
            }
            if ($targetClass === 'parent') {
                \App\PicoHP\CompilerInvariant::check($this->currentClassName !== null);
                $classMeta = $this->classRegistry[$this->currentClassName];
                \App\PicoHP\CompilerInvariant::check($classMeta->parentName !== null);
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
                    \App\PicoHP\CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
                    return $this->buildExpr($arg->value);
                })
                ->toArray();
            // Pass $this as first argument for parent:: calls
            if ($expr->class->toString() === 'parent') {
                // Load $this from param 0 alloca
                \App\PicoHP\CompilerInvariant::check($this->currentThisPtr !== null);
                $thisVal = $this->builder->createLoad($this->currentThisPtr);
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = array_merge([$thisVal], $args);
            } else {
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = $args;
            }
            $qualifiedName = "{$targetClass}_{$methodName}";
            return $this->builder->createCall($qualifiedName, $allArgs, $methodSymbol->type->toBase());
        } elseif ($expr instanceof \PhpParser\Node\Expr\StaticPropertyFetch) {
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\VarLikeIdentifier);
            $className = $expr->class->toString();
            if ($className === 'self') {
                \App\PicoHP\CompilerInvariant::check($this->currentClassName !== null);
                $className = $this->currentClassName;
            }
            $propName = $expr->name->toString();
            $classMeta = $this->classRegistry[$className];
            $propType = $classMeta->staticProperties[$propName];
            $globalName = "{$className}_{$propName}";
            $globalPtr = new \App\PicoHP\LLVM\Value\Global_($globalName, $propType->toBase());
            if ($pData->lVal) {
                return $globalPtr;
            }
            return $this->builder->createLoad($globalPtr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Match_) {
            \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
            $count = $pData->mycount;
            $condVal = $this->buildExpr($expr->cond);
            $condType = $condVal->getType();

            $currentFunc = $this->currentFunction;
            $endBlock = $currentFunc->addBasicBlock("match_end{$count}");
            $endLabel = new Label($endBlock->getName());

            // Separate default arm from conditional arms
            $defaultArm = null;
            $conditionalArms = [];
            foreach ($expr->arms as $arm) {
                if ($arm->conds === null) {
                    $defaultArm = $arm;
                } else {
                    $conditionalArms[] = $arm;
                }
            }

            // Create blocks for each arm body and next-check
            $armBlocks = [];
            $nextBlocks = [];
            foreach ($conditionalArms as $i => $arm) {
                $armBlocks[] = $currentFunc->addBasicBlock("match_arm{$count}_{$i}");
                // Only create next-check blocks for non-last arms
                if ($i + 1 < count($conditionalArms)) {
                    $nextBlocks[] = $currentFunc->addBasicBlock("match_next{$count}_{$i}");
                }
            }
            $defaultBlock = $defaultArm !== null
                ? $currentFunc->addBasicBlock("match_default{$count}")
                : $endBlock;

            // Determine result type from first arm body, allocate result
            $firstBody = $defaultArm !== null ? $defaultArm->body : $conditionalArms[0]->body;
            $firstBodyType = $this->resolveMatchArmType($firstBody);
            $resultPtr = $this->builder->createAlloca("match_result{$count}", $firstBodyType);

            // Emit condition checks and arm bodies
            foreach ($conditionalArms as $i => $arm) {
                \App\PicoHP\CompilerInvariant::check($arm->conds !== null);

                if (count($arm->conds) === 1) {
                    $armCondVal = $this->buildExpr($arm->conds[0]);
                    $isFloat = $condType === BaseType::FLOAT;
                    $cmpResult = $this->builder->createInstruction(
                        $isFloat ? 'fcmp oeq' : 'icmp eq',
                        [$condVal, $armCondVal],
                        resultType: BaseType::BOOL
                    );
                } else {
                    // Multiple conditions: OR them together
                    $orResult = null;
                    foreach ($arm->conds as $armCond) {
                        $armCondVal = $this->buildExpr($armCond);
                        $isFloat = $condType === BaseType::FLOAT;
                        $cmpResult = $this->builder->createInstruction(
                            $isFloat ? 'fcmp oeq' : 'icmp eq',
                            [$condVal, $armCondVal],
                            resultType: BaseType::BOOL
                        );
                        if ($orResult === null) {
                            $orResult = $cmpResult;
                        } else {
                            $orResult = $this->builder->createInstruction('or', [$orResult, $cmpResult], resultType: BaseType::BOOL);
                        }
                    }
                    $cmpResult = $orResult;
                    \App\PicoHP\CompilerInvariant::check($cmpResult !== null);
                }

                $armLabel = new Label($armBlocks[$i]->getName());
                if ($i + 1 < count($conditionalArms)) {
                    $fallthrough = new Label($nextBlocks[$i]->getName());
                } else {
                    $fallthrough = new Label($defaultBlock->getName());
                }
                $this->builder->createBranch([$cmpResult, $armLabel, $fallthrough]);

                // Emit arm body
                $this->builder->setInsertPoint($armBlocks[$i]);
                $bodyVal = $this->buildExpr($arm->body);
                $this->builder->createStore($bodyVal, $resultPtr);
                $this->builder->createBranch([$endLabel]);

                // Set insert point to next check block
                if ($i + 1 < count($conditionalArms)) {
                    $this->builder->setInsertPoint($nextBlocks[$i]);
                }
            }

            // Emit default arm
            if ($defaultArm !== null) {
                $this->builder->setInsertPoint($defaultBlock);
                $bodyVal = $this->buildExpr($defaultArm->body);
                $this->builder->createStore($bodyVal, $resultPtr);
                $this->builder->createBranch([$endLabel]);
            }

            // Continue from end block
            $this->builder->setInsertPoint($endBlock);
            return $this->builder->createLoad($resultPtr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Ternary) {
            \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
            $count = $pData->mycount;

            $condVal = $this->buildExpr($expr->cond);
            if ($condVal->getType() !== BaseType::BOOL) {
                $condVal = $this->builder->createInstruction('icmp ne', [$condVal, new Constant(0, $condVal->getType())], resultType: BaseType::BOOL);
            }

            $thenBB = $this->currentFunction->addBasicBlock("ternary_then{$count}");
            $elseBB = $this->currentFunction->addBasicBlock("ternary_else{$count}");
            $endBB = $this->currentFunction->addBasicBlock("ternary_end{$count}");
            $this->builder->createBranch([$condVal, new Label($thenBB->getName()), new Label($elseBB->getName())]);

            $this->builder->setInsertPoint($thenBB);
            $thenVal = $this->buildExpr($expr->if ?? $expr->cond);
            $resultPtr = $this->builder->createAlloca("ternary_result{$count}", $thenVal->getType());
            $this->builder->createStore($thenVal, $resultPtr);
            $this->builder->createBranch([new Label($endBB->getName())]);

            $this->builder->setInsertPoint($elseBB);
            $elseVal = $this->buildExpr($expr->else);
            $this->builder->createStore($elseVal, $resultPtr);
            $this->builder->createBranch([new Label($endBB->getName())]);

            $this->builder->setInsertPoint($endBB);
            return $this->builder->createLoad($resultPtr);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Isset_) {
            // isset($x) on nullable ptr: check if not null
            \App\PicoHP\CompilerInvariant::check(count($expr->vars) === 1);
            $val = $this->buildExpr($expr->vars[0]);
            return $this->builder->createInstruction('icmp ne', [$val, new \App\PicoHP\LLVM\Value\NullConstant()], resultType: BaseType::BOOL);
        } elseif ($expr instanceof \PhpParser\Node\Expr\Instanceof_) {
            $objVal = $this->buildExpr($expr->expr);
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            $targetClass = $expr->class->toString();

            // Load runtime type_id from field 0 using the static type for GEP
            // All class structs share i32 type_id at index 0 by convention
            $staticType = $this->getExprResolvedType($expr->expr);
            // For non-object types (e.g. mixed), fall back to the RHS class name for GEP
            $gepClass = $staticType->isObject() ? $staticType->getClassName() : $targetClass;
            // For interface/abstract types without a concrete struct, use first descendant
            if (!isset($this->typeIdMap[$gepClass])) {
                $descendants = $this->findDescendants($gepClass);
                \App\PicoHP\CompilerInvariant::check(count($descendants) > 0, "no concrete types for instanceof {$targetClass}");
                $gepClass = $descendants[0];
            }
            $typeIdPtr = $this->builder->createStructGEP($gepClass, $objVal, 0, BaseType::INT);
            $typeIdVal = $this->builder->createLoad($typeIdPtr);

            // Collect all type_ids that match: the target class + all concrete descendants
            $matchIds = [];
            if (isset($this->typeIdMap[$targetClass])) {
                $matchIds[] = $this->typeIdMap[$targetClass];
            }
            foreach ($this->findDescendants($targetClass) as $desc) {
                if (isset($this->typeIdMap[$desc])) {
                    $matchIds[] = $this->typeIdMap[$desc];
                }
            }

            $matchIds = array_values(array_unique($matchIds));
            if (count($matchIds) === 0) {
                return new Constant(0, BaseType::BOOL);
            }
            if (count($matchIds) === 1) {
                return $this->builder->createInstruction('icmp eq', [$typeIdVal, new Constant($matchIds[0], BaseType::INT)], resultType: BaseType::BOOL);
            }
            // Multiple targets: OR chain
            $result = $this->builder->createInstruction('icmp eq', [$typeIdVal, new Constant($matchIds[0], BaseType::INT)], resultType: BaseType::BOOL);
            for ($i = 1; $i < count($matchIds); $i++) {
                $cmp = $this->builder->createInstruction('icmp eq', [$typeIdVal, new Constant($matchIds[$i], BaseType::INT)], resultType: BaseType::BOOL);
                $result = $this->builder->createInstruction('or', [$result, $cmp], resultType: BaseType::BOOL);
            }
            return $result;
        } elseif ($expr instanceof \PhpParser\Node\Expr\Throw_) {
            // throw new ClassName(args...)
            \App\PicoHP\CompilerInvariant::check($expr->expr instanceof \PhpParser\Node\Expr\New_);
            $newExpr = $expr->expr;
            \App\PicoHP\CompilerInvariant::check($newExpr->class instanceof \PhpParser\Node\Name);
            $className = $newExpr->class->toString();
            $classMeta = $this->classRegistry[$className];
            $typeId = $this->typeIdMap[$className] ?? 0;
            $objPtr = $this->builder->createObjectAlloc($className, $typeId);
            // Store type_id in field 0
            $typeIdPtr = $this->builder->createStructGEP($className, $objPtr, 0, BaseType::INT);
            $this->builder->createStore(new Constant($typeId, BaseType::INT), $typeIdPtr);
            // Call constructor if it exists
            if (isset($classMeta->methods['__construct'])) {
                $ctorSymbol = $classMeta->methods['__construct'];
                $args = $this->buildArgsWithDefaults($newExpr->args, $ctorSymbol);
                /** @var array<ValueAbstract> $allArgs */
                $allArgs = array_merge([$objPtr], $args);
                $ctorOwner = $classMeta->methodOwner['__construct'] ?? $className;
                $qualifiedName = "{$ctorOwner}___construct";
                $this->builder->createCall($qualifiedName, $allArgs, BaseType::VOID);
            }
            $typeId = $this->typeIdMap[$className] ?? 0;
            $this->builder->createThrow($objPtr, $typeId);
            return new Void_();
        } elseif ($expr instanceof \PhpParser\Node\Expr\Array_) {
            // Array literal as standalone expression (e.g. return [])
            return $this->builder->createArrayNew();
        } else {
            throw new \Exception("unknown node type in expr: " . get_class($expr));
        }
    }

    /**
     * @param 'add'|'sub' $intOpcode
     * @param 'fadd'|'fsub' $floatOpcode
     */
    protected function buildCompoundAssign(
        \PhpParser\Node\Expr\AssignOp\Plus|\PhpParser\Node\Expr\AssignOp\Minus $expr,
        string $intOpcode,
        string $floatOpcode
    ): ValueAbstract {
        $lhs = $expr->var;
        if ($lhs instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            \App\PicoHP\CompilerInvariant::check($lhs->dim !== null);
            $innerType = $this->getExprResolvedType($lhs->var);
            \App\PicoHP\CompilerInvariant::check(
                $innerType->isArray() || $innerType->isMixed(),
                "line {$lhs->getStartLine()}, compound assignment on this target is not supported"
            );
            $arrVarExpr = $lhs->var;
            $arrPtrPtr = $this->buildExpr($arrVarExpr);
            $arrPtr = $this->builder->createLoad($arrPtrPtr);
            $arrayType = $this->getExprResolvedType($arrVarExpr);
            $elemBaseType = $arrayType->isMixed() ? BaseType::PTR : $arrayType->getElementBaseType();
            $keyOrIdxVal = $this->buildExpr($lhs->dim);
            $shouldUseMapSet = ($arrayType->isArray() && $arrayType->hasStringKeys())
                || $keyOrIdxVal->getType() === BaseType::STRING;

            if ($shouldUseMapSet) {
                $getFunc = 'pico_map_get_' . match ($elemBaseType) {
                    BaseType::INT => 'int',
                    BaseType::FLOAT => 'float',
                    BaseType::BOOL => 'bool',
                    BaseType::STRING => 'str',
                    BaseType::PTR => 'ptr',
                    default => throw new \RuntimeException('unsupported map get type'),
                };
                $oldVal = $this->builder->createCall($getFunc, [$arrPtr, $keyOrIdxVal], $elemBaseType);
            } else {
                $oldVal = $this->builder->createArrayGet($arrPtr, $keyOrIdxVal, $elemBaseType);
            }

            $rhs = $this->buildExpr($expr->expr);
            $newVal = $this->buildArithmeticBinResult($oldVal, $rhs, $intOpcode, $floatOpcode);

            if ($shouldUseMapSet) {
                $setFunc = 'pico_map_set_' . match ($elemBaseType) {
                    BaseType::INT => 'int',
                    BaseType::FLOAT => 'float',
                    BaseType::BOOL => 'bool',
                    BaseType::STRING => 'str',
                    BaseType::PTR => 'ptr',
                    default => throw new \RuntimeException('unsupported map set type'),
                };
                $this->builder->createCall($setFunc, [$arrPtr, $keyOrIdxVal, $newVal], BaseType::VOID);
            } else {
                $this->builder->createArraySet($arrPtr, $keyOrIdxVal, $newVal, $elemBaseType);
            }

            return $newVal;
        }

        $ptr = $this->buildExpr($lhs);
        $oldVal = $this->builder->createLoad($ptr);
        $rhs = $this->buildExpr($expr->expr);
        $newVal = $this->buildArithmeticBinResult($oldVal, $rhs, $intOpcode, $floatOpcode);
        $this->builder->createStore($newVal, $ptr);

        return $newVal;
    }

    /**
     * Integer/float add or sub, mirroring {@see buildExpr} binary op handling for `+` / `-`.
     *
     * @param 'add'|'sub' $intOpcode
     * @param 'fadd'|'fsub' $floatOpcode
     */
    protected function buildArithmeticBinResult(
        ValueAbstract $lval,
        ValueAbstract $rval,
        string $intOpcode,
        string $floatOpcode
    ): ValueAbstract {
        // Match BinaryOp `+` / `-` handling for mixed/ptr-backed values.
        if ($lval->getType() === BaseType::PTR || $lval->getType() === BaseType::STRING) {
            $lval = $this->builder->createPtrToInt($lval);
        }
        if ($rval->getType() === BaseType::PTR || $rval->getType() === BaseType::STRING) {
            $rval = $this->builder->createPtrToInt($rval);
        }

        $isFloat = $lval->getType() === BaseType::FLOAT;
        $operandType = $lval->getType();

        return $this->builder->createInstruction($isFloat ? $floatOpcode : $intOpcode, [$lval, $rval], resultType: $operandType);
    }

    /**
     * Get a pointer (for load/store) from a variable expression.
     * Handles local variables and static properties.
     */
    protected function resolveVarPtr(\PhpParser\Node\Expr $var): ValueAbstract
    {
        if ($var instanceof \PhpParser\Node\Expr\StaticPropertyFetch) {
            \App\PicoHP\CompilerInvariant::check($var->class instanceof \PhpParser\Node\Name);
            \App\PicoHP\CompilerInvariant::check($var->name instanceof \PhpParser\Node\VarLikeIdentifier);
            $className = $var->class->toString();
            if ($className === 'self' || $className === 'static') {
                \App\PicoHP\CompilerInvariant::check($this->currentClassName !== null);
                $className = $this->currentClassName;
            }
            $classMeta = $this->classRegistry[$className];
            $propType = $classMeta->staticProperties[$var->name->toString()];
            return new \App\PicoHP\LLVM\Value\Global_("{$className}_{$var->name->toString()}", $propType->toBase());
        }
        return PicoHPData::getPData($var)->getValue();
    }

    /**
     * Build argument values, filling in defaults for missing args.
     *
     * @param array<\PhpParser\Node\Arg|\PhpParser\Node\VariadicPlaceholder> $args
     * @return array<ValueAbstract>
     */
    protected function buildArgsWithDefaults(array $args, \App\PicoHP\SymbolTable\Symbol $funcSymbol): array
    {
        $paramCount = count($funcSymbol->params);

        // If params aren't populated yet (pre-registered symbol), just build args as-is
        if ($paramCount === 0 && count($args) > 0) {
            $result = [];
            foreach ($args as $arg) {
                \App\PicoHP\CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
                $result[] = $this->buildExpr($arg->value);
            }
            return $result;
        }

        // Build a map of name => position for named arg resolution
        $nameToPos = array_flip($funcSymbol->paramNames);

        // Map args to positions (handle both positional and named)
        /** @var array<int, \PhpParser\Node\Expr> */
        $argsByPos = [];
        $positionalIndex = 0;
        foreach ($args as $arg) {
            \App\PicoHP\CompilerInvariant::check($arg instanceof \PhpParser\Node\Arg);
            if ($arg->name !== null) {
                // Named argument
                $name = $arg->name->toString();
                \App\PicoHP\CompilerInvariant::check(isset($nameToPos[$name]), "unknown named argument: {$name}");
                $argsByPos[$nameToPos[$name]] = $arg->value;
            } else {
                // Positional argument
                $argsByPos[$positionalIndex] = $arg->value;
                $positionalIndex++;
            }
        }

        // Build values for each param position
        $result = [];
        for ($i = 0; $i < $paramCount; $i++) {
            if (isset($argsByPos[$i])) {
                $val = $this->buildExpr($argsByPos[$i]);
            } elseif (isset($funcSymbol->defaults[$i])) {
                $val = $this->buildDefaultValue($funcSymbol->defaults[$i]);
            } else {
                throw new \RuntimeException("missing argument {$i} for function {$funcSymbol->name} with no default");
            }
            // Coerce int to float when param expects float (e.g. int|float union widened to float)
            if ($val->getType() === BaseType::INT && $funcSymbol->params[$i]->toBase() === BaseType::FLOAT) {
                $val = $this->builder->createSiToFp($val);
            }
            $result[] = $val;
        }
        return $result;
    }

    protected function buildDefaultValue(\PhpParser\Node\Expr $expr): ValueAbstract
    {
        if ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return new Constant($expr->value, BaseType::INT);
        }
        if ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return new Constant($expr->value, BaseType::FLOAT);
        }
        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return $this->builder->createStringConstant($expr->value);
        }
        if ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $name = $expr->name->toLowerString();
            if ($name === 'null') {
                return new NullConstant();
            }
            return new Constant($name === 'true' ? 1 : 0, BaseType::BOOL);
        }
        if ($expr instanceof \PhpParser\Node\Expr\Array_) {
            // Empty array default: $param = []
            return $this->builder->createArrayNew();
        }
        if ($expr instanceof \PhpParser\Node\Expr\UnaryMinus && $expr->expr instanceof \PhpParser\Node\Scalar\Int_) {
            return new Constant(-$expr->expr->value, BaseType::INT);
        }
        if ($expr instanceof \PhpParser\Node\Expr\ClassConstFetch) {
            // Enum case as default value — resolve directly
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $className = $expr->class->toString();
            $caseName = $expr->name->toString();
            if (isset($this->enumRegistry[$className])) {
                $tag = $this->enumRegistry[$className]->getCaseTag($caseName);
                return new Constant($tag, BaseType::INT);
            }
            throw new \RuntimeException("unsupported ClassConstFetch default: {$className}::{$caseName}");
        }
        throw new \RuntimeException('unsupported default value type: ' . get_class($expr));
    }

    protected function getExprType(\PhpParser\Node\Expr $expr): \App\PicoHP\PicoType
    {
        $pData = PicoHPData::getPData($expr);
        return $pData->getSymbol()->type;
    }

    protected function getExprResolvedType(\PhpParser\Node\Expr $expr): \App\PicoHP\PicoType
    {
        if ($expr instanceof \PhpParser\Node\Expr\Variable) {
            if (is_string($expr->name) && $expr->name === 'this' && $this->currentClassName !== null) {
                return \App\PicoHP\PicoType::object($this->currentClassName);
            }
            return PicoHPData::getPData($expr)->getSymbol()->type;
        }
        if ($expr instanceof \PhpParser\Node\Expr\PropertyFetch) {
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $objType = $this->getExprResolvedType($expr->var);
            if ($objType->isMixed()) {
                return \App\PicoHP\PicoType::fromString('mixed');
            }
            $className = $objType->getClassName();
            $classMeta = $this->classRegistry[$className];
            $propName = $expr->name->toString();
            // Resolve property type through descendants (interface/abstract)
            if (!isset($classMeta->properties[$propName])) {
                foreach ($this->findDescendants($className) as $descName) {
                    $descMeta = $this->classRegistry[$descName];
                    if (isset($descMeta->properties[$propName])) {
                        return $descMeta->getPropertyType($propName);
                    }
                }
            }
            return $classMeta->getPropertyType($propName);
        }
        if ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            $arrType = $this->getExprResolvedType($expr->var);
            if ($arrType->isMixed()) {
                return \App\PicoHP\PicoType::fromString('mixed');
            }
            \App\PicoHP\CompilerInvariant::check($arrType->isArray());
            return $arrType->getElementType();
        }
        if ($expr instanceof \PhpParser\Node\Expr\MethodCall) {
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Identifier);
            $objType = $this->getExprResolvedType($expr->var);
            if ($objType->isMixed()) {
                return \App\PicoHP\PicoType::fromString('mixed');
            }
            $classMeta = $this->classRegistry[$objType->getClassName()];
            return $classMeta->methods[$expr->name->toString()]->type;
        }
        if ($expr instanceof \PhpParser\Node\Expr\ClassConstFetch) {
            \App\PicoHP\CompilerInvariant::check($expr->class instanceof \PhpParser\Node\Name);
            $className = $expr->class->toString();
            if (isset($this->enumRegistry[$className])) {
                return \App\PicoHP\PicoType::enum($className);
            }
            return \App\PicoHP\PicoType::object($className);
        }
        if ($expr instanceof \PhpParser\Node\Expr\FuncCall) {
            \App\PicoHP\CompilerInvariant::check($expr->name instanceof \PhpParser\Node\Name);
            $fn = $expr->name->toLowerString();
            // Built-in functions that return the same type as their first array arg
            if ($fn === 'array_reverse' || $fn === 'array_merge') {
                \App\PicoHP\CompilerInvariant::check(count($expr->args) >= 1 && $expr->args[0] instanceof \PhpParser\Node\Arg);
                return $this->getExprResolvedType($expr->args[0]->value);
            }
            return PicoHPData::getPData($expr)->getSymbol()->type;
        }
        throw new \RuntimeException('getExprResolvedType: unsupported expr type ' . get_class($expr));
    }

    /**
     * Find all concrete descendants of $className (interface implementors + subclass tree).
     * Filters out abstract intermediates so dispatch targets only have real implementations.
     *
     * @return array<string>
     */
    protected function findDescendants(string $className): array
    {
        $descendants = [];
        foreach ($this->classRegistry as $name => $meta) {
            if ($meta->isAbstract) {
                continue;
            }
            if (in_array($className, $meta->interfaces, true) || $this->isDescendantOf($name, $className)) {
                $descendants[] = $name;
            }
        }
        return $descendants;
    }

    protected function isDescendantOf(string $className, string $ancestor): bool
    {
        $current = $className;
        while (isset($this->classRegistry[$current])) {
            $parent = $this->classRegistry[$current]->parentName;
            if ($parent === null) {
                return false;
            }
            if ($parent === $ancestor) {
                return true;
            }
            $current = $parent;
        }
        return false;
    }

    /**
     * Check if a method on $className needs virtual dispatch.
     * True when the method is abstract on the owning class.
     */
    protected function needsVirtualDispatch(string $className, string $methodName): bool
    {
        $classMeta = $this->classRegistry[$className];
        return isset($classMeta->methods[$methodName]) && $classMeta->methods[$methodName]->isAbstract;
    }

    /**
     * Emit virtual dispatch for a method call on an interface/abstract-typed variable.
     * Loads the type_id from field 0 of the object, then emits a switch to dispatch
     * to the correct concrete class method.
     *
     * @param array<\App\PicoHP\LLVM\ValueAbstract> $allArgs
     */
    protected function emitVirtualDispatch(
        ValueAbstract $objVal,
        string $interfaceName,
        string $methodName,
        array $allArgs,
        BaseType $returnType,
    ): ValueAbstract {
        \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);

        $implementors = $this->findDescendants($interfaceName);
        \App\PicoHP\CompilerInvariant::check(count($implementors) > 0, "no implementors found for {$interfaceName}");

        $vd = $this->vdispatchCount++;

        // Load type_id from field 0 (all structs have i32 type_id as first field)
        $typeIdPtr = $this->builder->createStructGEP($implementors[0], $objVal, 0, BaseType::INT);
        $typeIdVal = $this->builder->createLoad($typeIdPtr);

        // If only one implementor, skip the switch
        if (count($implementors) === 1) {
            $implClass = $implementors[0];
            $implMeta = $this->classRegistry[$implClass];
            $ownerClass = $implMeta->methodOwner[$methodName] ?? $implClass;
            return $this->builder->createCall("{$ownerClass}_{$methodName}", $allArgs, $returnType);
        }

        // Multiple implementors: emit switch on type_id
        $resultPtr = $this->builder->createAlloca("vd{$vd}_result", $returnType);
        $endBB = $this->currentFunction->addBasicBlock("vd{$vd}_end");

        $caseBBs = [];
        foreach ($implementors as $i => $implClass) {
            $caseBBs[$implClass] = $this->currentFunction->addBasicBlock("vd{$vd}_case{$i}");
        }

        $defaultBB = $caseBBs[$implementors[0]];

        $switchCases = [];
        foreach ($implementors as $implClass) {
            $typeId = $this->typeIdMap[$implClass];
            $switchCases[] = "i32 {$typeId}, label %{$caseBBs[$implClass]->getName()}";
        }
        $casesStr = implode(' ', $switchCases);
        $this->builder->addLine("switch i32 {$typeIdVal->render()}, label %{$defaultBB->getName()} [{$casesStr}]", 1);

        foreach ($implementors as $implClass) {
            $this->builder->setInsertPoint($caseBBs[$implClass]);
            $implMeta = $this->classRegistry[$implClass];
            $ownerClass = $implMeta->methodOwner[$methodName] ?? $implClass;
            $callResult = $this->builder->createCall("{$ownerClass}_{$methodName}", $allArgs, $returnType);
            $this->builder->createStore($callResult, $resultPtr);
            $this->builder->createBranch([new Label($endBB->getName())]);
        }

        $this->builder->setInsertPoint($endBB);
        return $this->builder->createLoad($resultPtr);
    }

    /**
     * Emit stores for instance property default values.
     * Supports: int, float, string, bool, null, array literals (int/string/negative-int elements).
     * Unsupported default expressions (new, ClassConstFetch, binary exprs) are rejected.
     */
    protected function emitPropertyDefaults(string $className, \App\PicoHP\SymbolTable\ClassMetadata $classMeta, ValueAbstract $objPtr): void
    {
        foreach ($classMeta->propertyDefaults as $propName => $default) {
            $fieldIndex = $classMeta->getPropertyIndex($propName);
            $fieldType = $classMeta->getPropertyType($propName)->toBase();
            $fieldPtr = $this->builder->createStructGEP($className, $objPtr, $fieldIndex, $fieldType);
            if ($default instanceof \PhpParser\Node\Expr\Array_) {
                $arrPtr = $this->builder->createArrayNew();
                foreach ($default->items as $item) {
                    if ($item->value instanceof \PhpParser\Node\Scalar\Int_) {
                        $this->builder->createArrayPush($arrPtr, new Constant($item->value->value, BaseType::INT), BaseType::INT);
                    } elseif ($item->value instanceof \PhpParser\Node\Scalar\String_) {
                        $strVal = $this->builder->createStringConstant($item->value->value);
                        $this->builder->createArrayPush($arrPtr, $strVal, BaseType::STRING);
                    } elseif ($item->value instanceof \PhpParser\Node\Expr\UnaryMinus
                        && $item->value->expr instanceof \PhpParser\Node\Scalar\Int_) {
                        $this->builder->createArrayPush($arrPtr, new Constant(-$item->value->expr->value, BaseType::INT), BaseType::INT);
                    } else {
                        /** @phpstan-ignore-next-line */
                        \App\PicoHP\CompilerInvariant::check(false, "unsupported array element type in property default: " . get_class($item->value));
                    }
                }
                $this->builder->createStore($arrPtr, $fieldPtr);
            } elseif ($default instanceof \PhpParser\Node\Scalar\Int_) {
                $this->builder->createStore(new Constant($default->value, BaseType::INT), $fieldPtr);
            } elseif ($default instanceof \PhpParser\Node\Scalar\Float_) {
                $this->builder->createStore(new Constant($default->value, BaseType::FLOAT), $fieldPtr);
            } elseif ($default instanceof \PhpParser\Node\Scalar\String_) {
                $strVal = $this->builder->createStringConstant($default->value);
                $this->builder->createStore($strVal, $fieldPtr);
            } elseif ($default instanceof \PhpParser\Node\Expr\UnaryMinus
                && $default->expr instanceof \PhpParser\Node\Scalar\Int_) {
                $this->builder->createStore(new Constant(-$default->expr->value, BaseType::INT), $fieldPtr);
            } elseif ($default instanceof \PhpParser\Node\Expr\UnaryMinus
                && $default->expr instanceof \PhpParser\Node\Scalar\Float_) {
                $this->builder->createStore(new Constant(-$default->expr->value, BaseType::FLOAT), $fieldPtr);
            } elseif ($default instanceof \PhpParser\Node\Expr\ConstFetch) {
                $name = $default->name->toLowerString();
                if ($name === 'null') {
                    $this->builder->createStore(new NullConstant(), $fieldPtr);
                } elseif ($name === 'true') {
                    $this->builder->createStore(new Constant(1, BaseType::BOOL), $fieldPtr);
                } elseif ($name === 'false') {
                    $this->builder->createStore(new Constant(0, BaseType::BOOL), $fieldPtr);
                }
            } elseif ($default instanceof \PhpParser\Node\Expr\ClassConstFetch) {
                \App\PicoHP\CompilerInvariant::check($default->class instanceof \PhpParser\Node\Name);
                \App\PicoHP\CompilerInvariant::check($default->name instanceof \PhpParser\Node\Identifier);
                $enumName = $default->class->toString();
                $caseName = $default->name->toString();
                \App\PicoHP\CompilerInvariant::check(isset($this->enumRegistry[$enumName]), "enum {$enumName} not found for property default");
                $tag = $this->enumRegistry[$enumName]->getCaseTag($caseName);
                $this->builder->createStore(new Constant($tag, BaseType::INT), $fieldPtr);
            } else {
                /** @phpstan-ignore-next-line */
                \App\PicoHP\CompilerInvariant::check(false, "unsupported property default type: " . get_class($default));
            }
        }
    }

    /**
     * Emit virtual dispatch for property access on interface-typed values.
     * Switches on type_id, GEPs into each implementor's struct.
     *
     * @param bool $lVal Whether to return the pointer (lval) or load the value
     */
    protected function emitVirtualPropertyDispatch(
        ValueAbstract $objVal,
        string $interfaceName,
        string $propName,
        bool $lVal,
    ): ValueAbstract {
        \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);

        $implementors = [];
        foreach ($this->findDescendants($interfaceName) as $name) {
            if (isset($this->classRegistry[$name]->properties[$propName])) {
                $implementors[] = $name;
            }
        }
        \App\PicoHP\CompilerInvariant::check(count($implementors) > 0, "no implementors with property {$propName} for interface {$interfaceName}");

        $vd = $this->vdispatchCount++;

        // Load type_id from field 0
        $typeIdPtr = $this->builder->createStructGEP($implementors[0], $objVal, 0, BaseType::INT);
        $typeIdVal = $this->builder->createLoad($typeIdPtr);

        // Resolve property type from first implementor
        $fieldType = $this->classRegistry[$implementors[0]]->getPropertyType($propName)->toBase();

        if (count($implementors) === 1) {
            $implClass = $implementors[0];
            $implMeta = $this->classRegistry[$implClass];
            $fieldIndex = $implMeta->getPropertyIndex($propName);
            $fieldPtr = $this->builder->createStructGEP($implClass, $objVal, $fieldIndex, $fieldType);
            if ($lVal) {
                return $fieldPtr;
            }
            return $this->builder->createLoad($fieldPtr);
        }

        // Multiple implementors: switch on type_id
        // lVal stores a pointer (GEP result), so alloca must be ptr-sized
        $resultPtr = $this->builder->createAlloca("vdp{$vd}_result", $lVal ? BaseType::PTR : $fieldType);
        $endBB = $this->currentFunction->addBasicBlock("vdp{$vd}_end");

        $caseBBs = [];
        foreach ($implementors as $i => $implClass) {
            $caseBBs[$implClass] = $this->currentFunction->addBasicBlock("vdp{$vd}_case{$i}");
        }

        $defaultBB = $caseBBs[$implementors[0]];
        $switchCases = [];
        foreach ($implementors as $implClass) {
            $typeId = $this->typeIdMap[$implClass];
            $switchCases[] = "i32 {$typeId}, label %{$caseBBs[$implClass]->getName()}";
        }
        $casesStr = implode(' ', $switchCases);
        $this->builder->addLine("switch i32 {$typeIdVal->render()}, label %{$defaultBB->getName()} [{$casesStr}]", 1);

        foreach ($implementors as $implClass) {
            $this->builder->setInsertPoint($caseBBs[$implClass]);
            $implMeta = $this->classRegistry[$implClass];
            $fieldIndex = $implMeta->getPropertyIndex($propName);
            $fieldPtr = $this->builder->createStructGEP($implClass, $objVal, $fieldIndex, $fieldType);
            if ($lVal) {
                // GEP returns a ptr regardless of field type; store as ptr
                $this->builder->addLine("store ptr {$fieldPtr->render()}, ptr {$resultPtr->render()}", 1);
            } else {
                $loadedVal = $this->builder->createLoad($fieldPtr);
                $this->builder->createStore($loadedVal, $resultPtr);
            }
            $this->builder->createBranch([new Label($endBB->getName())]);
        }

        $this->builder->setInsertPoint($endBB);
        return $this->builder->createLoad($resultPtr);
    }

    /**
     * Determine the BaseType of a match arm body expression from the semantic analysis data.
     */
    protected function resolveMatchArmType(\PhpParser\Node\Expr $expr): BaseType
    {
        if ($expr instanceof \PhpParser\Node\Scalar\String_) {
            return BaseType::STRING;
        }
        if ($expr instanceof \PhpParser\Node\Scalar\Int_) {
            return BaseType::INT;
        }
        if ($expr instanceof \PhpParser\Node\Scalar\Float_) {
            return BaseType::FLOAT;
        }
        if ($expr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $name = $expr->name->toLowerString();
            if ($name === 'null') {
                return BaseType::PTR;
            }
            return BaseType::BOOL;
        }
        // Fall back to resolved expression type (handles method/property calls too).
        $line = $expr->getStartLine();
        $exprType = get_debug_type($expr);
        try {
            return $this->getExprResolvedType($expr)->toBase();
        } catch (\Throwable $e) {
            throw new \RuntimeException("line {$line}, could not resolve match arm type for {$exprType}", 0, $e);
        }
    }

    protected function buildShortCircuit(\PhpParser\Node\Expr\BinaryOp $expr, PicoHPData $pData): ValueAbstract
    {
        \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
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
        if ($arrayType->isArray() && $arrayType->hasStringKeys()) {
            return $this->buildMapInit($arrayExpr, $arrayType);
        }
        $arrPtr = $this->builder->createArrayNew();
        $elementType = $arrayType->isMixed() ? BaseType::PTR : $arrayType->getElementBaseType();
        foreach ($arrayExpr->items as $item) {
            $elemVal = $this->buildExpr($item->value);
            $this->builder->createArrayPush($arrPtr, $elemVal, $elementType);
        }
        return $arrPtr;
    }

    protected function buildMapInit(\PhpParser\Node\Expr\Array_ $arrayExpr, \App\PicoHP\PicoType $arrayType): ValueAbstract
    {
        $mapPtr = $this->builder->createCall('pico_map_new', [], BaseType::PTR);
        $elementType = $arrayType->getElementBaseType();
        foreach ($arrayExpr->items as $item) {
            \App\PicoHP\CompilerInvariant::check($item->key !== null);
            $keyVal = $this->buildExpr($item->key);
            $elemVal = $this->buildExpr($item->value);
            $setFunc = 'pico_map_set_' . match ($elementType) {
                BaseType::INT => 'int',
                BaseType::FLOAT => 'float',
                BaseType::BOOL => 'bool',
                BaseType::STRING => 'str',
                BaseType::PTR => 'ptr',
                default => throw new \RuntimeException("unsupported map value type: {$elementType->value}"),
            };
            $this->builder->createCall($setFunc, [$mapPtr, $keyVal, $elemVal], BaseType::VOID);
        }
        return $mapPtr;
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

    /**
     * Get all type_ids that should match a catch clause for a given class name.
     * Includes the class itself and all subclasses that have type_ids.
     *
     * @return array<int>
     */
    protected function getMatchingTypeIds(string $className): array
    {
        $ids = [];
        foreach ($this->typeIdMap as $name => $id) {
            if ($this->isSubclassOf($name, $className)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    protected function isSubclassOf(string $className, string $parentName): bool
    {
        if ($className === $parentName) {
            return true;
        }
        $meta = $this->classRegistry[$className] ?? null;
        if ($meta === null || $meta->parentName === null) {
            return false;
        }
        return $this->isSubclassOf($meta->parentName, $parentName);
    }

    protected function buildTryCatch(\PhpParser\Node\Stmt\TryCatch $stmt, PicoHPData $pData): void
    {
        \App\PicoHP\CompilerInvariant::check($this->currentFunction !== null);
        $currentFunction = $this->currentFunction;
        $count = $pData->mycount;

        $hasCatches = count($stmt->catches) > 0;

        // Create basic blocks
        $tryBB = $currentFunction->addBasicBlock("try{$count}");
        $catchDispatchBB = $hasCatches ? $currentFunction->addBasicBlock("catch_dispatch{$count}") : null;
        $finallyBB = $stmt->finally !== null ? $currentFunction->addBasicBlock("finally{$count}") : null;
        $endBB = $currentFunction->addBasicBlock("try_end{$count}");

        $tryLabel = new Label($tryBB->getName());
        $finallyLabel = $finallyBB !== null ? new Label($finallyBB->getName()) : null;
        $endLabel = new Label($endBB->getName());

        // After try/catch: where to go
        $continueLabel = $finallyLabel ?? $endLabel;

        // Allocate jmp_buf and call setjmp
        $jmpBuf = $this->builder->createJmpBufAlloca();
        $setjmpRet = $this->builder->createSetjmp($jmpBuf);
        $isException = $this->builder->createInstruction('icmp ne', [$setjmpRet, new Constant(0, BaseType::INT)], resultType: BaseType::BOOL);
        $exceptionTarget = $catchDispatchBB !== null ? new Label($catchDispatchBB->getName()) : $continueLabel;
        $this->builder->createBranch([$isException, $exceptionTarget, $tryLabel]);

        // -- try body --
        $this->builder->setInsertPoint($tryBB);
        $this->buildStmts($stmt->stmts);
        $this->builder->createEhPop();
        $this->builder->createBranch([$continueLabel]);

        // -- catch dispatch --
        if ($catchDispatchBB !== null) {
            $this->builder->setInsertPoint($catchDispatchBB);
            $this->builder->createEhPop();

            $catchCount = count($stmt->catches);
            /** @var array<\App\PicoHP\LLVM\BasicBlock> $catchBodyBBs */
            $catchBodyBBs = [];
            for ($i = 0; $i < $catchCount; $i++) {
                $catchBodyBBs[] = $currentFunction->addBasicBlock("catch{$count}_{$i}");
            }

            // Emit type-check chain
            for ($i = 0; $i < $catchCount; $i++) {
                $catch = $stmt->catches[$i];
                \App\PicoHP\CompilerInvariant::check(count($catch->types) > 0);
                $catchTypeName = $catch->types[0]->toString();
                $catchBodyLabel = new Label($catchBodyBBs[$i]->getName());

                if ($i + 1 < $catchCount) {
                    $nextCheckBB = $currentFunction->addBasicBlock("catch_check{$count}_" . ($i + 1));
                    $nextCheckLabel = new Label($nextCheckBB->getName());
                } else {
                    $nextCheckBB = null;
                    $nextCheckLabel = $continueLabel;
                }

                $matchingIds = $this->getMatchingTypeIds($catchTypeName);
                if (count($matchingIds) === 1) {
                    $matches = $this->builder->createEhMatchesType($matchingIds[0]);
                } else {
                    $matches = $this->builder->createEhMatchesType($matchingIds[0]);
                    for ($j = 1; $j < count($matchingIds); $j++) {
                        $nextMatch = $this->builder->createEhMatchesType($matchingIds[$j]);
                        $matches = $this->builder->createInstruction('or', [$matches, $nextMatch], resultType: BaseType::BOOL);
                    }
                }
                $this->builder->createBranch([$matches, $catchBodyLabel, $nextCheckLabel]);

                if ($nextCheckBB !== null) {
                    $this->builder->setInsertPoint($nextCheckBB);
                }
            }

            // Emit catch body blocks
            for ($i = 0; $i < $catchCount; $i++) {
                $catch = $stmt->catches[$i];
                $this->builder->setInsertPoint($catchBodyBBs[$i]);
                if ($catch->var !== null) {
                    $exceptionPtr = $this->builder->createEhGetException();
                    $catchVarPData = PicoHPData::getPData($catch->var);
                    $catchVarPtr = $catchVarPData->getValue();
                    $this->builder->createStore($exceptionPtr, $catchVarPtr);
                }
                $this->builder->createEhClear();
                $this->buildStmts($catch->stmts);
                $this->builder->createBranch([$continueLabel]);
            }
        }

        // -- finally block --
        if ($stmt->finally !== null) {
            /** @var \App\PicoHP\LLVM\BasicBlock $finallyBB */
            $this->builder->setInsertPoint($finallyBB);
            $this->buildStmts($stmt->finally->stmts);
            $this->builder->createBranch([$endLabel]);
        }

        // -- end --
        $this->builder->setInsertPoint($endBB);
    }
}
