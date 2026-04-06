<?php

declare(strict_types=1);

namespace App\PicoHP\Pass\IRGen;

use App\PicoHP\{BaseType, ClassSymbol, CompilerInvariant};
use App\PicoHP\LLVM\{ValueAbstract};
use App\PicoHP\LLVM\Value\{Constant, Void_, Label, NullConstant};
use App\PicoHP\SymbolTable\PicoHPData;

trait VirtualDispatchTrait
{
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
        CompilerInvariant::check($this->ctx->function !== null);

        $implementors = $this->findDescendants($interfaceName);
        CompilerInvariant::check(count($implementors) > 0, "no implementors found for {$interfaceName}");

        $vd = $this->vdispatchCount++;

        // Load type_id from field 0 (all structs have i32 type_id as first field)
        $typeIdPtr = $this->builder->createStructGEP(ClassSymbol::mangle($implementors[0]), $objVal, 0, BaseType::INT);
        $typeIdVal = $this->builder->createLoad($typeIdPtr);

        // If only one implementor, skip the switch
        if (count($implementors) === 1) {
            $implClass = $implementors[0];
            $implMeta = $this->classRegistry[$implClass];
            $ownerClass = $implMeta->methodOwner[$methodName] ?? $implClass;
            return $this->builder->createCall(ClassSymbol::llvmMethodSymbol($ownerClass, $methodName), $allArgs, $returnType);
        }

        // Multiple implementors: emit switch on type_id
        $resultPtr = $returnType !== BaseType::VOID ? $this->builder->createAlloca("vd{$vd}_result", $returnType) : null;
        $endBB = $this->ctx->function->addBasicBlock("vd{$vd}_end");

        $caseBBs = [];
        foreach ($implementors as $i => $implClass) {
            $caseBBs[$implClass] = $this->ctx->function->addBasicBlock("vd{$vd}_case{$i}");
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
            $callResult = $this->builder->createCall(ClassSymbol::llvmMethodSymbol($ownerClass, $methodName), $allArgs, $returnType);
            if ($resultPtr !== null) {
                $this->builder->createStore($callResult, $resultPtr);
            }
            $this->builder->createBranch([new Label($endBB->getName())]);
        }

        $this->builder->setInsertPoint($endBB);
        if ($resultPtr === null) {
            return new Void_();
        }
        return $this->builder->createLoad($resultPtr);
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
        CompilerInvariant::check($this->ctx->function !== null);

        $implementors = [];
        foreach ($this->findDescendants($interfaceName) as $name) {
            if (isset($this->classRegistry[$name]->properties[$propName])) {
                $implementors[] = $name;
            }
        }
        CompilerInvariant::check(count($implementors) > 0, "no implementors with property {$propName} for interface {$interfaceName}");

        $vd = $this->vdispatchCount++;

        // Load type_id from field 0
        $typeIdPtr = $this->builder->createStructGEP(ClassSymbol::mangle($implementors[0]), $objVal, 0, BaseType::INT);
        $typeIdVal = $this->builder->createLoad($typeIdPtr);

        // Resolve property type from first implementor
        $fieldType = $this->classRegistry[$implementors[0]]->getPropertyType($propName)->toBase();

        if (count($implementors) === 1) {
            $implClass = $implementors[0];
            $implMeta = $this->classRegistry[$implClass];
            $fieldIndex = $implMeta->getPropertyIndex($propName);
            $fieldPtr = $this->builder->createStructGEP(ClassSymbol::mangle($implClass), $objVal, $fieldIndex, $fieldType);
            if ($lVal) {
                return $fieldPtr;
            }
            return $this->builder->createLoad($fieldPtr);
        }

        // Multiple implementors: switch on type_id
        // lVal stores a pointer (GEP result), so alloca must be ptr-sized
        $resultPtr = $this->builder->createAlloca("vdp{$vd}_result", $lVal ? BaseType::PTR : $fieldType);
        $endBB = $this->ctx->function->addBasicBlock("vdp{$vd}_end");

        $caseBBs = [];
        foreach ($implementors as $i => $implClass) {
            $caseBBs[$implClass] = $this->ctx->function->addBasicBlock("vdp{$vd}_case{$i}");
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
            $fieldPtr = $this->builder->createStructGEP(ClassSymbol::mangle($implClass), $objVal, $fieldIndex, $fieldType);
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
        CompilerInvariant::check($this->ctx->function !== null);
        $currentFunction = $this->ctx->function;
        $count = $pData->mycount;

        $hasCatches = count($stmt->catches) > 0;

        // Create basic blocks
        $catchDispatchBB = $hasCatches ? $currentFunction->addBasicBlock("catch_dispatch{$count}") : null;
        $finallyBB = $stmt->finally !== null ? $currentFunction->addBasicBlock("finally{$count}") : null;
        $endBB = $currentFunction->addBasicBlock("try_end{$count}");

        $finallyLabel = $finallyBB !== null ? new Label($finallyBB->getName()) : null;
        $endLabel = new Label($endBB->getName());
        $continueLabel = $finallyLabel ?? $endLabel;

        // Allocate a slot to hold the caught exception pointer
        $exceptionSlot = $this->builder->createAlloca("exc_slot{$count}", BaseType::PTR);
        $this->builder->createStore(new NullConstant(), $exceptionSlot);

        // Set up try context so emitThrowingCall branches here on error
        $savedTryContext = $this->ctx->tryContext;
        if ($catchDispatchBB !== null) {
            $this->ctx->tryContext = [
                'exceptionSlot' => $exceptionSlot,
                'catchLabel' => new Label($catchDispatchBB->getName()),
            ];
        }

        // -- try body (inline, no separate BB needed) --
        $this->buildStmts($stmt->stmts);
        $this->builder->createBranch([$continueLabel]);

        // Restore try context
        $this->ctx->tryContext = $savedTryContext;

        // -- catch dispatch --
        if ($catchDispatchBB !== null) {
            $this->builder->setInsertPoint($catchDispatchBB);

            // Load the exception pointer from the slot
            $excPtr = $this->builder->createLoad($exceptionSlot);

            // Load type_id from field 0 of exception object
            // All exception structs have type_id (i32) as first field
            $typeIdPtr = $this->builder->createStructGEP('Exception', $excPtr, 0, BaseType::INT);
            $typeIdVal = $this->builder->createLoad($typeIdPtr);

            $catchCount = count($stmt->catches);
            /** @var array<\App\PicoHP\LLVM\BasicBlock> $catchBodyBBs */
            $catchBodyBBs = [];
            for ($i = 0; $i < $catchCount; $i++) {
                $catchBodyBBs[] = $currentFunction->addBasicBlock("catch{$count}_{$i}");
            }

            // Emit type-check chain using direct icmp on the type_id
            for ($i = 0; $i < $catchCount; $i++) {
                $catch = $stmt->catches[$i];
                CompilerInvariant::check(count($catch->types) > 0);
                $catchTypeName = ClassSymbol::fqcnFromResolvedName($catch->types[0], $this->currentNamespace());
                $catchBodyLabel = new Label($catchBodyBBs[$i]->getName());

                if ($i + 1 < $catchCount) {
                    $nextCheckBB = $currentFunction->addBasicBlock("catch_check{$count}_" . ($i + 1));
                    $nextCheckLabel = new Label($nextCheckBB->getName());
                } else {
                    $nextCheckBB = null;
                    $nextCheckLabel = $continueLabel;
                }

                $matchingIds = $this->getMatchingTypeIds($catchTypeName);
                // Build OR chain: type_id == id1 || type_id == id2 || ...
                $matches = $this->builder->createInstruction(
                    'icmp eq',
                    [$typeIdVal, new Constant($matchingIds[0], BaseType::INT)],
                    resultType: BaseType::BOOL,
                );
                for ($j = 1; $j < count($matchingIds); $j++) {
                    $nextMatch = $this->builder->createInstruction(
                        'icmp eq',
                        [$typeIdVal, new Constant($matchingIds[$j], BaseType::INT)],
                        resultType: BaseType::BOOL,
                    );
                    $matches = $this->builder->createInstruction('or', [$matches, $nextMatch], resultType: BaseType::BOOL);
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
                    $catchVarPData = PicoHPData::getPData($catch->var);
                    $catchVarPtr = $catchVarPData->getValue();
                    $this->builder->createStore($excPtr, $catchVarPtr);
                }
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
