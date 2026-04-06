<?php

declare(strict_types=1);

namespace App\PicoHP\Pass\IRGen;

use App\PicoHP\{BaseType, ClassSymbol, CompilerInvariant};
use App\PicoHP\LLVM\{Builder, IRLine, ValueAbstract};
use App\PicoHP\LLVM\Value\{Constant, Void_, Label, Param, NullConstant};
use App\PicoHP\SymbolTable\{EnumMetadata, PicoHPData};

trait BuildStmtTrait
{
    /**
     * @param array<\PhpParser\Node> $stmts
     */
    public function buildStmts(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            CompilerInvariant::check($stmt instanceof \PhpParser\Node\Stmt);
            $this->buildStmt($stmt);
        }
    }

    public function buildStmt(\PhpParser\Node\Stmt $stmt): void
    {
        $pData = PicoHPData::getPData($stmt);
        $stmtLine = $stmt->getStartLine();
        if ($stmtLine > 0 && $this->module->getDebugInfo()->getCurrentScope() !== null) {
            $this->builder->setDebugLine($stmtLine);
        }

        if ($stmt instanceof \PhpParser\Node\Stmt\Function_) {
            $funcSymbol = $pData->getSymbol();
            CompilerInvariant::check($funcSymbol->func === true);
            $savedTryContext = $this->ctx->tryContext;
            $this->ctx->tryContext = null;
            $this->ctx->function = $this->module->addFunction($stmt->name->toString(), $funcSymbol->type, $funcSymbol->params, $funcSymbol->canThrow);
            $debugInfo = $this->module->getDebugInfo();
            if ($debugInfo->getCompileUnitId() !== null) {
                $line = $stmt->getStartLine();
                $sourceFile = $stmt->getAttribute('pico_source_file');
                $fileId = is_string($sourceFile) ? $debugInfo->getOrCreateFileId($sourceFile) : null;
                $spId = $debugInfo->addSubprogram($stmt->name->toString(), max($line, 1), $fileId);
                $this->ctx->function->dbgSubprogramId = $spId;
                $debugInfo->setCurrentScope($spId);
                $this->builder->setDebugLine(max($line, 1));
            }
            $bb = $this->ctx->function->addBasicBlock("entry");
            $this->builder->setInsertPoint($bb);
            if ($pData->stubbed) {
                $this->builder->emitUnimplementedAbort($stmt->name->toString());
            } else {
                try {
                    $scope = $pData->getScope();
                    foreach ($scope->symbols as $symbol) {
                        if ($symbol->func) {
                            continue;
                        }
                        $symbol->value = $this->buildSymbolAlloca($symbol);
                    }
                    $this->buildParams($stmt->params);
                    if ($stmt->name->toString() === 'main') {
                        $this->emitMainArgvConversion($stmt->params);
                    }
                    $this->buildStmts($stmt->stmts);
                } catch (\Throwable $e) {
                    // Clear partial IR and replace with a clean abort stub
                    fwrite(STDERR, "[IR-STUB] {$stmt->name->toString()}: {$e->getMessage()}\n");
                    CompilerInvariant::check($this->ctx->function !== null);
                    $this->ctx->function->clearBlocks();
                    $bb = $this->ctx->function->addBasicBlock('entry');
                    $this->builder->setInsertPoint($bb);
                    $this->builder->emitUnimplementedAbort($stmt->name->toString());
                    $pData->stubbed = true;
                }
            }
            if (!$pData->stubbed && $funcSymbol->type->toBase() === BaseType::VOID) {
                $currentBB = $this->builder->getCurrentBasicBlock();
                if ($currentBB === null || !$currentBB->hasTerminator()) {
                    if ($funcSymbol->canThrow) {
                        $okResult = $this->builder->createResultOk(new Void_(), BaseType::VOID);
                        $structType = Builder::resultTypeName(BaseType::VOID);
                        $this->builder->addLine("ret {$structType} {$okResult->render()}", 1);
                    } else {
                        $this->builder->createRetVoid();
                    }
                }
            }
            $this->module->getDebugInfo()->setCurrentScope(null);
            $this->builder->setDebugLine(null);
            $this->ctx->tryContext = $savedTryContext;
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
            if (is_null($stmt->expr)) {
                // bare return; — emit ret void for void functions, ret 0/null for others
                $funcRetType = $this->ctx->function !== null ? $this->ctx->function->getReturnType()->toBase() : BaseType::VOID;
                if ($funcRetType === BaseType::VOID) {
                    if ($this->ctx->function !== null && $this->ctx->function->canThrow) {
                        $okResult = $this->builder->createResultOk(new Void_(), BaseType::VOID);
                        $structType = Builder::resultTypeName(BaseType::VOID);
                        $this->builder->addLine("ret {$structType} {$okResult->render()}", 1);
                    } else {
                        $this->builder->createRetVoid();
                    }
                } else {
                    $zero = ($funcRetType === BaseType::PTR || $funcRetType === BaseType::STRING)
                        ? new NullConstant($funcRetType)
                        : new Constant(0, $funcRetType);
                    $this->builder->createInstruction('ret', [$zero], false);
                }
            } elseif (!is_null($stmt->expr)) {
                $val = $this->buildExpr($stmt->expr);
                $funcRetType = $this->ctx->function !== null ? $this->ctx->function->getReturnType()->toBase() : null;
                if ($funcRetType === BaseType::VOID) {
                    // void function with return $expr — evaluate for side effects, return void
                    $this->builder->createRetVoid();
                } elseif ($this->ctx->function !== null && $this->ctx->function->canThrow) {
                    $retType = $this->ctx->function->getReturnType()->toBase();
                    $okResult = $this->builder->createResultOk($val, $retType);
                    $structType = Builder::resultTypeName($retType);
                    $this->builder->addLine("ret {$structType} {$okResult->render()}", 1);
                } else {
                    $this->builder->createInstruction('ret', [$val], false);
                }
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Nop) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Declare_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                $val = $this->buildExpr($expr);
                if ($val->getType() === BaseType::BOOL) {
                    CompilerInvariant::check($this->ctx->function !== null);
                    $count = $pData->mycount;
                    $printBB = $this->ctx->function->addBasicBlock("echo_bool{$count}");
                    $endBB = $this->ctx->function->addBasicBlock("echo_end{$count}");
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
            CompilerInvariant::check($this->ctx->function !== null);
            $currentFunction = $this->ctx->function;
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

            $cond = $this->coerceToBool($this->buildExpr($stmt->cond));
            $this->builder->createBranch([$cond, $thenLabel, $nextLabel]);

            // then block
            $this->builder->setInsertPoint($thenBB);
            $this->buildStmts($stmt->stmts);
            $this->builder->createBranch([$endLabel]);

            // elseif blocks
            for ($i = 0; $i < $elseifCount; $i++) {
                $elseif = $stmt->elseifs[$i];
                $this->builder->setInsertPoint($nextBB);
                $elseifCond = $this->coerceToBool($this->buildExpr($elseif->cond));

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
            CompilerInvariant::check($this->ctx->function !== null);
            $condBB = $this->ctx->function->addBasicBlock("cond{$pData->mycount}");
            $bodyBB = $this->ctx->function->addBasicBlock("body{$pData->mycount}");
            $endBB = $this->ctx->function->addBasicBlock("end{$pData->mycount}");
            $condLabel = new Label($condBB->getName());
            $bodyLabel = new Label($bodyBB->getName());
            $endLabel = new Label($endBB->getName());
            $this->continueTargets[] = $condBB->getName();
            $this->breakTargets[] = $endBB->getName();
            $this->builder->createBranch([$condLabel]);
            $this->builder->setInsertPoint($condBB);
            $cond = $this->coerceToBool($this->buildExpr($stmt->cond));
            $this->builder->createBranch([$cond, $bodyLabel, $endLabel]);
            $this->builder->setInsertPoint($bodyBB);
            $this->buildStmts($stmt->stmts);
            $this->builder->createBranch([$condLabel]);
            array_pop($this->continueTargets);
            array_pop($this->breakTargets);
            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Class_) {
            CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
            $fqcn = ClassSymbol::fqcn($this->currentNamespace(), $stmt->name->toString());
            CompilerInvariant::check(isset($this->classRegistry[$fqcn]));
            $classMeta = $this->classRegistry[$fqcn];
            $llvmClass = ClassSymbol::mangle($fqcn);
            // Instance struct layout is emitted once in emitStructDefinitionsForRegistry() for all registry classes.
            // Emit static properties as globals
            foreach ($classMeta->staticProperties as $propName => $propType) {
                $llvmType = $propType->toBase()->toLLVM();
                $default = $classMeta->staticDefaults[$propName] ?? null;
                $initVal = $llvmType === 'ptr' ? 'null' : '0';
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
                    } elseif ($name === 'false') {
                        $initVal = '0';
                    } elseif ($name === 'null') {
                        $initVal = $llvmType === 'ptr' ? 'null' : '0';
                    }
                }
                $this->module->addLine(new IRLine("@{$llvmClass}_{$propName} = global {$llvmType} {$initVal}"));
            }
            $this->ctx->className = $fqcn;
            $this->buildStmts($stmt->stmts);
            $this->ctx->className = null;
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
            // Handled by struct type definition
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
            // Abstract methods have no body — skip IR generation
            if ($stmt->stmts === null) {
                return;
            }
            $savedTryContext = $this->ctx->tryContext;
            $this->ctx->tryContext = null;
            CompilerInvariant::check($this->ctx->className !== null);
            $methodName = $stmt->name->toString();
            $funcSymbol = $pData->getSymbol();
            $qualifiedName = ClassSymbol::llvmMethodSymbol($this->ctx->className, $methodName);
            // Methods get $this (ptr) as first param
            $thisParam = new \App\PicoHP\PicoType(\App\PicoHP\BaseType::PTR);
            $allParams = array_merge([$thisParam], $funcSymbol->params);
            $this->ctx->function = $this->module->addFunction($qualifiedName, $funcSymbol->type, $allParams);
            $debugInfo = $this->module->getDebugInfo();
            if ($debugInfo->getCompileUnitId() !== null) {
                $line = $stmt->getStartLine();
                $sourceFile = $stmt->getAttribute('pico_source_file');
                $fileId = is_string($sourceFile) ? $debugInfo->getOrCreateFileId($sourceFile) : null;
                $spId = $debugInfo->addSubprogram($qualifiedName, max($line, 1), $fileId);
                $this->ctx->function->dbgSubprogramId = $spId;
                $debugInfo->setCurrentScope($spId);
                $this->builder->setDebugLine(max($line, 1));
            }
            $bb = $this->ctx->function->addBasicBlock("entry");
            $this->builder->setInsertPoint($bb);
            if ($pData->stubbed) {
                $this->builder->emitUnimplementedAbort($qualifiedName);
            } else {
                try {
                    $scope = $pData->getScope();
                    foreach ($scope->symbols as $symbol) {
                        if ($symbol->func) {
                            continue;
                        }
                        $symbol->value = $this->buildSymbolAlloca($symbol);
                    }
                    // Store $this param (param 0) into its alloca
                    $thisSymbol = $scope->symbols['this'] ?? null;
                    CompilerInvariant::check($thisSymbol !== null);
                    CompilerInvariant::check($thisSymbol->value !== null);
                    $this->builder->createStore(new Param(0, \App\PicoHP\BaseType::PTR), $thisSymbol->value);
                    $this->ctx->thisPtr = $thisSymbol->value;
                    // Store remaining params (offset by 1)
                    $paramIndex = 1;
                    foreach ($stmt->params as $param) {
                        $paramPData = PicoHPData::getPData($param);
                        $type = $paramPData->getSymbol()->type;
                        $this->builder->createStore(new Param($paramIndex++, $type->toBase()), $paramPData->getValue());
                    }
                    $this->buildStmts($stmt->stmts);
                    if ($funcSymbol->type->toBase() === \App\PicoHP\BaseType::VOID) {
                        $currentBB = $this->builder->getCurrentBasicBlock();
                        if ($currentBB === null || !$currentBB->hasTerminator()) {
                            $this->builder->createRetVoid();
                        }
                    }
                } catch (\Throwable $e) {
                    fwrite(STDERR, "[IR-STUB] {$qualifiedName}: {$e->getMessage()}\n");
                    CompilerInvariant::check($this->ctx->function !== null);
                    $this->ctx->function->clearBlocks();
                    $bb = $this->ctx->function->addBasicBlock('entry');
                    $this->builder->setInsertPoint($bb);
                    $this->builder->emitUnimplementedAbort($qualifiedName);
                }
            }
            $this->module->getDebugInfo()->setCurrentScope(null);
            $this->builder->setDebugLine(null);
            $this->ctx->tryContext = $savedTryContext;
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Do_) {
            CompilerInvariant::check($this->ctx->function !== null);
            $condBB = $this->ctx->function->addBasicBlock("cond{$pData->mycount}");
            $bodyBB = $this->ctx->function->addBasicBlock("body{$pData->mycount}");
            $endBB = $this->ctx->function->addBasicBlock("end{$pData->mycount}");
            $condLabel = new Label($condBB->getName());
            $bodyLabel = new Label($bodyBB->getName());
            $endLabel = new Label($endBB->getName());
            $this->continueTargets[] = $condBB->getName();
            $this->breakTargets[] = $endBB->getName();
            $this->builder->createBranch([$bodyLabel]);
            $this->builder->setInsertPoint($bodyBB);
            $this->buildStmts($stmt->stmts);
            $this->builder->createBranch([$condLabel]);
            $this->builder->setInsertPoint($condBB);
            $cond = $this->coerceToBool($this->buildExpr($stmt->cond));
            $this->builder->createBranch([$cond, $bodyLabel, $endLabel]);
            array_pop($this->continueTargets);
            array_pop($this->breakTargets);
            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\For_) {
            CompilerInvariant::check($this->ctx->function !== null);
            $condBB = $this->ctx->function->addBasicBlock("cond{$pData->mycount}");
            $bodyBB = $this->ctx->function->addBasicBlock("body{$pData->mycount}");
            $forContinueBB = $this->ctx->function->addBasicBlock("for_continue{$pData->mycount}");
            $endBB = $this->ctx->function->addBasicBlock("end{$pData->mycount}");
            $condLabel = new Label($condBB->getName());
            $bodyLabel = new Label($bodyBB->getName());
            $forContinueLabel = new Label($forContinueBB->getName());
            $endLabel = new Label($endBB->getName());
            $this->continueTargets[] = $forContinueBB->getName();
            $this->breakTargets[] = $endBB->getName();
            foreach ($stmt->init as $init) {
                $this->buildExpr($init);
            }
            $this->builder->createBranch([$condLabel]);
            $this->builder->setInsertPoint($condBB);
            $conds = [];
            foreach ($stmt->cond as $cond) {
                $conds[] = $this->buildExpr($cond);
            }
            CompilerInvariant::check(count($conds) > 0);
            $this->builder->createBranch([$this->coerceToBool($conds[0]), $bodyLabel, $endLabel]);
            $this->builder->setInsertPoint($bodyBB);
            $this->buildStmts($stmt->stmts);
            $this->builder->createBranch([$forContinueLabel]);
            $this->builder->setInsertPoint($forContinueBB);
            foreach ($stmt->loop as $loop) {
                $this->buildExpr($loop);
            }
            $this->builder->createBranch([$condLabel]);
            array_pop($this->continueTargets);
            array_pop($this->breakTargets);
            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Switch_) {
            CompilerInvariant::check($this->ctx->function !== null);
            $count = $pData->mycount;
            $condVal = $this->buildExpr($stmt->cond);

            $endBB = $this->ctx->function->addBasicBlock("switch_end{$count}");
            $this->breakTargets[] = $endBB->getName();

            // Build case blocks and collect switch cases
            $caseBBs = [];
            $defaultBB = null;
            foreach ($stmt->cases as $i => $case) {
                $bb = $this->ctx->function->addBasicBlock("switch_case{$count}_{$i}");
                $caseBBs[] = $bb;
                if ($case->cond === null) {
                    $defaultBB = $bb;
                }
            }

            $isStringSwitch = $condVal->getType() === BaseType::PTR || $condVal->getType() === BaseType::STRING;
            $defaultLabel = $defaultBB !== null ? $defaultBB->getName() : $endBB->getName();

            if ($isStringSwitch) {
                // String switches: lower to if/else chain with pico_string_eq
                foreach ($stmt->cases as $i => $case) {
                    if ($case->cond !== null) {
                        $caseVal = $this->buildExpr($case->cond);
                        $cmpResult = $this->builder->createCall('pico_string_eq', [$condVal, $caseVal], BaseType::INT);
                        $cmpBool = $this->builder->createInstruction('icmp ne', [$cmpResult, new Constant(0, BaseType::INT)], resultType: BaseType::BOOL);
                        $nextBB = $this->ctx->function->addBasicBlock("switch_next{$count}_{$i}");
                        $this->builder->createBranch([$cmpBool, new Label($caseBBs[$i]->getName()), new Label($nextBB->getName())]);
                        $this->builder->setInsertPoint($nextBB);
                    }
                }
                $this->builder->createBranch([new Label($defaultLabel)]);
            } else {
                // Integer switches: use LLVM switch instruction
                // Coerce ptr (nullable enum) to int for switch
                if ($condVal->getType() === BaseType::PTR || $condVal->getType() === BaseType::STRING) {
                    $condVal = $this->builder->createPtrToInt($condVal);
                }
                $switchCases = [];
                foreach ($stmt->cases as $i => $case) {
                    if ($case->cond !== null) {
                        $caseVal = $this->buildExpr($case->cond);
                        $switchCases[] = "{$condVal->getType()->toLLVM()} {$caseVal->render()}, label %{$caseBBs[$i]->getName()}";
                    }
                }
                $casesStr = implode(' ', $switchCases);
                $this->builder->addLine("switch {$condVal->getType()->toLLVM()} {$condVal->render()}, label %{$defaultLabel} [{$casesStr}]", 1);
            }

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
            // If switch_end has no terminator (no default case and no break fallthrough), seal it
            if (!$endBB->hasTerminator()) {
                $this->builder->setInsertPoint($endBB);
            } else {
                $this->builder->setInsertPoint($endBB);
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Break_) {
            CompilerInvariant::check(count($this->breakTargets) > 0, 'break outside of switch or loop');
            $target = end($this->breakTargets);
            $this->builder->createBranch([new Label($target)]);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Continue_) {
            CompilerInvariant::check(count($this->continueTargets) > 0, 'continue outside of loop');
            $target = end($this->continueTargets);
            $this->builder->createBranch([new Label($target)]);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Foreach_) {
            CompilerInvariant::check($this->ctx->function !== null);
            $count = $pData->mycount;

            $arrayPtr = $this->buildExpr($stmt->expr);
            $arrayType = $this->getExprResolvedType($stmt->expr);
            $elemBaseType = $arrayType->isMixed() ? BaseType::PTR : $arrayType->getElementBaseType();

            CompilerInvariant::check($stmt->valueVar instanceof \PhpParser\Node\Expr\Variable);
            $valueVarPData = PicoHPData::getPData($stmt->valueVar);
            $valuePtr = $valueVarPData->getValue();

            $counterPtr = $this->builder->createAlloca("foreach_i{$count}", BaseType::INT);
            $this->builder->createStore(new Constant(0, BaseType::INT), $counterPtr);

            $condBB = $this->ctx->function->addBasicBlock("foreach_cond{$count}");
            $bodyBB = $this->ctx->function->addBasicBlock("foreach_body{$count}");
            $foreachContinueBB = $this->ctx->function->addBasicBlock("foreach_continue{$count}");
            $endBB = $this->ctx->function->addBasicBlock("foreach_end{$count}");
            $condLabel = new Label($condBB->getName());
            $bodyLabel = new Label($bodyBB->getName());
            $foreachContinueLabel = new Label($foreachContinueBB->getName());
            $endLabel = new Label($endBB->getName());

            $this->continueTargets[] = $foreachContinueBB->getName();
            $this->breakTargets[] = $endBB->getName();

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
                CompilerInvariant::check($stmt->keyVar instanceof \PhpParser\Node\Expr\Variable);
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
            $this->builder->createBranch([$foreachContinueLabel]);
            $this->builder->setInsertPoint($foreachContinueBB);
            $idx = $this->builder->createLoad($counterPtr);
            $idxNext = $this->builder->createInstruction('add', [$idx, new Constant(1, BaseType::INT)]);
            $this->builder->createStore($idxNext, $counterPtr);
            $this->builder->createBranch([$condLabel]);

            array_pop($this->continueTargets);
            array_pop($this->breakTargets);

            $this->builder->setInsertPoint($endBB);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Interface_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Trait_) {
            // Traits are inlined into classes at semantic analysis time; nothing to emit
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Enum_) {
            CompilerInvariant::check($stmt->name instanceof \PhpParser\Node\Identifier);
            $enumFqcn = ClassSymbol::fqcn($this->currentNamespace(), $stmt->name->toString());
            $enumMeta = $this->enumRegistry[$enumFqcn];
            $llvmEnum = ClassSymbol::mangle($enumFqcn);
            // Emit backing value lookup table as a global array of ptrs
            if ($enumMeta->backingType === 'string') {
                $ptrs = [];
                foreach ($enumMeta->cases as $caseName => $tag) {
                    $value = $enumMeta->backingValues[$caseName];
                    CompilerInvariant::check(is_string($value));
                    $constVal = $this->builder->createStringConstant($value);
                    $ptrs[] = "ptr {$constVal->render()}";
                }
                $count = count($ptrs);
                $init = implode(', ', $ptrs);
                $this->module->addLine(new IRLine("@{$llvmEnum}_values = global [{$count} x ptr] [{$init}]"));
            }
            // Auto-generate tryFrom/from for backed enums (emit as standalone functions,
            // then restore builder state since we're between top-level statements)
            if ($enumMeta->backingType !== null) {
                $savedFunc = $this->ctx->function;
                $savedBB = $this->builder->getCurrentBasicBlock();
                $this->emitEnumTryFrom($enumFqcn, $enumMeta);
                $this->emitEnumFrom($enumFqcn, $enumMeta);
                $this->ctx->function = $savedFunc;
                if ($savedBB !== null) {
                    $this->builder->setInsertPoint($savedBB);
                }
            }
            // Enum methods are handled by the missing-function stub emitter in Module::print()
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\ClassConst) {
            // Class constants — values resolved at compile time via ClassConstFetch
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\EnumCase) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Use_) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\GroupUse) {
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
            $childNs = $stmt->name !== null ? $stmt->name->toString() : '';
            $merged = $childNs === '' ? null : $childNs;
            $this->pushNamespace($merged);
            try {
                $this->buildStmts($stmt->stmts);
            } finally {
                $this->popNamespace();
            }
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\TryCatch) {
            $this->buildTryCatch($stmt, $pData);
        } elseif ($stmt instanceof \PhpParser\Node\Stmt\InlineHTML) {
            // TODO: create string constant?
        } else {
            throw new \Exception("unknown node type in stmt: " . get_class($stmt));
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

    /**
     * Convert raw C main(argc, argv) into a PicoArray for $argv.
     * After buildParams stores the raw ptr, replace $argv with the converted array.
     *
     * @param array<\PhpParser\Node\Param> $params
     */
    protected function emitMainArgvConversion(array $params): void
    {
        // Find $argc and $argv params
        $argcVal = null;
        $argvPtr = null;
        foreach ($params as $param) {
            CompilerInvariant::check($param->var instanceof \PhpParser\Node\Expr\Variable);
            if ($param->var->name === 'argc') {
                $argcVal = $this->builder->createLoad(PicoHPData::getPData($param)->getValue());
            } elseif ($param->var->name === 'argv') {
                $argvPtr = PicoHPData::getPData($param)->getValue();
            }
        }
        if ($argcVal === null || $argvPtr === null) {
            return;
        }
        // Load raw char** argv, convert to PicoArray
        $rawArgv = $this->builder->createLoad($argvPtr);
        $picoArray = new \App\PicoHP\LLVM\Value\Instruction('argv_arr', BaseType::PTR);
        $this->builder->addLine("{$picoArray->render()} = call ptr @pico_argv_to_array(i32 {$argcVal->render()}, ptr {$rawArgv->render()})", 1);
        // Store PicoArray back into $argv alloca
        $this->builder->createStore($picoArray, $argvPtr);
    }

    protected function buildSymbolAlloca(\App\PicoHP\SymbolTable\Symbol $symbol): ValueAbstract
    {
        $baseType = $symbol->type->toBase();
        // void-typed vars (unresolvable types from reflection) → treat as ptr
        if ($baseType === BaseType::VOID) {
            $baseType = BaseType::PTR;
        }
        return $this->builder->createAlloca($symbol->name, $baseType);
    }

    protected function emitPropertyDefaults(string $className, \App\PicoHP\SymbolTable\ClassMetadata $classMeta, ValueAbstract $objPtr): void
    {
        foreach ($classMeta->propertyDefaults as $propName => $default) {
            $fieldIndex = $classMeta->getPropertyIndex($propName);
            $fieldType = $classMeta->getPropertyType($propName)->toBase();
            $fieldPtr = $this->builder->createStructGEP(ClassSymbol::mangle($className), $objPtr, $fieldIndex, $fieldType);
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
                        CompilerInvariant::check(false, "unsupported array element type in property default: " . get_class($item->value));
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
                CompilerInvariant::check($default->class instanceof \PhpParser\Node\Name);
                CompilerInvariant::check($default->name instanceof \PhpParser\Node\Identifier);
                $enumName = ClassSymbol::fqcnFromResolvedName($default->class, $this->currentNamespace());
                $caseName = $default->name->toString();
                CompilerInvariant::check(isset($this->enumRegistry[$enumName]), "enum {$enumName} not found for property default");
                $tag = $this->enumRegistry[$enumName]->getCaseTag($caseName);
                $this->builder->createStore(new Constant($tag, BaseType::INT), $fieldPtr);
            } elseif ($default instanceof \PhpParser\Node\Expr\New_) {
                CompilerInvariant::check($default->class instanceof \PhpParser\Node\Name);
                $newClassName = ClassSymbol::fqcnFromResolvedName($default->class, $this->currentNamespace());
                $newMeta = $this->classRegistry[$newClassName] ?? null;
                $newTypeId = $this->typeIdMap[$newClassName] ?? 0;
                $newLlvm = ClassSymbol::mangle($newClassName);
                $newObjPtr = $this->builder->createObjectAlloc($newLlvm, $newTypeId);
                $newTypeIdPtr = $this->builder->createStructGEP($newLlvm, $newObjPtr, 0, BaseType::INT);
                $this->builder->createStore(new Constant($newTypeId, BaseType::INT), $newTypeIdPtr);
                if ($newMeta !== null) {
                    $this->emitPropertyDefaults($newClassName, $newMeta, $newObjPtr);
                    if (isset($newMeta->methods['__construct'])) {
                        $ctorSymbol = $newMeta->methods['__construct'];
                        $args = $this->buildArgsWithDefaults($default->args, $ctorSymbol);
                        $allArgs = array_merge([$newObjPtr], $args);
                        $ctorOwner = $newMeta->methodOwner['__construct'] ?? $newClassName;
                        $qualifiedName = ClassSymbol::llvmMethodSymbol($ctorOwner, '__construct');
                        $this->builder->createCall($qualifiedName, $allArgs, BaseType::VOID);
                    }
                }
                $this->builder->createStore($newObjPtr, $fieldPtr);
            } else {
                /** @phpstan-ignore-next-line */
                CompilerInvariant::check(false, "unsupported property default type: " . get_class($default));
            }
        }
    }

    /**
     * Emit stores for instance property default values.
     * Supports: int, float, string, bool, null, array literals (int/string/negative-int elements).
     * Unsupported default expressions (new, ClassConstFetch, binary exprs) are rejected.
     */
    protected function emitEnumTryFrom(string $enumFqcn, EnumMetadata $enumMeta): void
    {
        $llvmEnum = ClassSymbol::mangle($enumFqcn);
        $isString = $enumMeta->backingType === 'string';
        $paramType = $isString ? BaseType::STRING : BaseType::INT;
        $funcName = "{$llvmEnum}_tryFrom";

        $func = $this->module->addFunction($funcName, new \App\PicoHP\PicoType(BaseType::INT), [
            new \App\PicoHP\PicoType($paramType),
        ]);
        $entryBB = $func->addBasicBlock('entry');
        $this->builder->setInsertPoint($entryBB);

        $inputVal = new Param(0, $paramType);
        $resultPtr = $this->builder->createAlloca('tryfrom_result', BaseType::INT);
        // Default: -1 (not found — caller treats as null)
        $this->builder->createStore(new Constant(-1, BaseType::INT), $resultPtr);

        $endBB = $func->addBasicBlock('tryfrom_end');

        foreach ($enumMeta->cases as $caseName => $tag) {
            $matchBB = $func->addBasicBlock("tryfrom_match_{$tag}");
            $nextBB = $func->addBasicBlock("tryfrom_next_{$tag}");
            $backingValue = $enumMeta->backingValues[$caseName] ?? $tag;

            if ($isString) {
                CompilerInvariant::check(is_string($backingValue));
                $caseVal = $this->builder->createStringConstant($backingValue);
                $eqResult = $this->builder->createCall('pico_string_eq', [$inputVal, $caseVal], BaseType::INT);
                $cmp = $this->builder->createInstruction('icmp ne', [$eqResult, new Constant(0, BaseType::INT)], resultType: BaseType::BOOL);
            } else {
                CompilerInvariant::check(is_int($backingValue));
                $cmp = $this->builder->createInstruction('icmp eq', [$inputVal, new Constant($backingValue, BaseType::INT)], resultType: BaseType::BOOL);
            }
            $this->builder->createBranch([$cmp, new Label($matchBB->getName()), new Label($nextBB->getName())]);

            $this->builder->setInsertPoint($matchBB);
            $this->builder->createStore(new Constant($tag, BaseType::INT), $resultPtr);
            $this->builder->createBranch([new Label($endBB->getName())]);

            $this->builder->setInsertPoint($nextBB);
        }
        // Fall through: not found
        $this->builder->createBranch([new Label($endBB->getName())]);

        $this->builder->setInsertPoint($endBB);
        $result = $this->builder->createLoad($resultPtr);
        $this->builder->createInstruction('ret', [$result], false);
    }

    protected function emitEnumFrom(string $enumFqcn, EnumMetadata $enumMeta): void
    {
        $llvmEnum = ClassSymbol::mangle($enumFqcn);
        $isString = $enumMeta->backingType === 'string';
        $paramType = $isString ? BaseType::STRING : BaseType::INT;
        $funcName = "{$llvmEnum}_from";

        $func = $this->module->addFunction($funcName, new \App\PicoHP\PicoType(BaseType::INT), [
            new \App\PicoHP\PicoType($paramType),
        ]);
        $entryBB = $func->addBasicBlock('entry');
        $this->builder->setInsertPoint($entryBB);

        // from() delegates to tryFrom() then checks result
        $inputVal = new Param(0, $paramType);
        $tryResult = $this->builder->createCall("{$llvmEnum}_tryFrom", [$inputVal], BaseType::INT);
        // -1 means not found -> throw (for now just return the tryFrom result)
        $this->builder->createInstruction('ret', [$tryResult], false);
    }

    /**
     * Seal all basic blocks in the current function with unreachable.
     * Used after a catch in IR gen to satisfy LLVM's terminator requirement
     * when a function body fails partway through (partially-built control flow).
     */
    protected function sealAllBlocks(): void
    {
        if ($this->ctx->function === null) {
            return;
        }
        foreach ($this->ctx->function->getBasicBlocks() as $sealBB) {
            if (!$sealBB->hasTerminator()) {
                $this->builder->setInsertPoint($sealBB);
                $this->builder->addLine('unreachable', 1);
            }
        }
    }

    /**
     * Emit {@code %struct.FQCN_mangled = type { ... }} for every class in the registry.
     *
     * Reflection-registered classes (e.g. SPL exceptions) never appear as {@see \PhpParser\Node\Stmt\Class_}
     * in the merged AST, so {@see buildStmt} would not emit their LLVM type otherwise — but {@see createObjectAlloc}
     * and {@see createStructGEP} still reference {@code %struct.*}. User-defined classes use the same path so
     * struct layout stays single-sourced from {@see ClassMetadata::toLLVMStructFields()}.
     */
    protected function emitStructDefinitionsForRegistry(): void
    {
        foreach ($this->classRegistry as $fqcn => $meta) {
            $llvmClass = ClassSymbol::mangle($fqcn);
            $fields = $meta->toLLVMStructFields();
            $this->module->addLine(new IRLine("%struct.{$llvmClass} = type { {$fields} }"));
        }
    }
}
