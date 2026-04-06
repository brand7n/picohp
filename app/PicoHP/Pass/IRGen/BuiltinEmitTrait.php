<?php

declare(strict_types=1);

namespace App\PicoHP\Pass\IRGen;

use App\PicoHP\{BaseType, BuiltinMethodDef, ClassSymbol, CompilerInvariant, PicoType};
use App\PicoHP\LLVM\{Builder, ValueAbstract};
use App\PicoHP\LLVM\Value\{Constant, NullConstant, Void_, Label, Param};
use App\PicoHP\SymbolTable\{ClassMetadata, PicoHPData};

trait BuiltinEmitTrait
{
    /**
     * Emit struct types and method bodies for all builtin classes from the registry
     * (Exception hierarchy, etc.) that aren't defined in the user AST.
     */
    protected function emitBuiltinClasses(): void
    {
        $userClasses = $this->collectUserClassNames($this->stmts);

        foreach ($this->builtinRegistry->allClasses() as $classDef) {
            if ($classDef->isInterface) {
                continue;
            }
            $className = $classDef->name;
            if (!isset($this->classRegistry[$className])) {
                continue;
            }
            if (isset($userClasses[$className])) {
                continue;
            }
            $classMeta = $this->classRegistry[$className];
            $llvmClass = ClassSymbol::mangle($className);

            // Struct type is emitted by emitStructDefinitionsForRegistry() — only emit methods here
            foreach ($classDef->methods as $methodName => $methodDef) {
                $this->emitBuiltinMethod($className, $classMeta, $methodName, $methodDef);
            }
        }
    }

    /**
     * Emit a single builtin method body. Property accessors (getMessage, etc.)
     * emit real field loads; constructors store params to fields; everything else
     * is an abort stub.
     */
    protected function emitBuiltinMethod(string $className, ClassMetadata $classMeta, string $methodName, BuiltinMethodDef $methodDef): void
    {
        $llvmClass = ClassSymbol::mangle($className);
        $qualifiedName = ClassSymbol::llvmMethodSymbol($className, $methodName);

        if ($this->module->hasFunction($qualifiedName)) {
            return;
        }

        $thisParam = new PicoType(BaseType::PTR);
        $params = [$thisParam];
        foreach ($methodDef->params as $p) {
            $params[] = $p['type'];
        }

        $fn = $this->module->addFunction($qualifiedName, $methodDef->returnType, $params);
        $bb = $fn->addBasicBlock('entry');
        $this->builder->setInsertPoint($bb);

        if ($methodName === '__construct') {
            // Store each param into the corresponding property field
            $thisVal = new Param(0, BaseType::PTR);
            foreach ($methodDef->params as $i => $p) {
                $propName = $p['name'];
                $offset = $classMeta->propertyOffsets[$propName] ?? null;
                if ($offset !== null) {
                    $fieldPtr = $this->builder->createStructGEP($llvmClass, $thisVal, $offset, $p['type']->toBase());
                    $this->builder->createStore(new Param($i + 1, $p['type']->toBase()), $fieldPtr);
                }
            }
            $this->builder->createRetVoid();
        } elseif (str_starts_with($methodName, 'get') && $methodDef->params === []) {
            // Getter — try to find a matching property
            $propName = lcfirst(substr($methodName, 3));
            $offset = $classMeta->propertyOffsets[$propName] ?? null;
            if ($offset !== null) {
                $thisVal = new Param(0, BaseType::PTR);
                $fieldPtr = $this->builder->createStructGEP($llvmClass, $thisVal, $offset, $methodDef->returnType->toBase());
                $val = $this->builder->createLoad($fieldPtr);
                $this->builder->createInstruction('ret', [$val], false);
            } else {
                // No matching property — stub with empty string for string returns
                if ($methodDef->returnType->toBase() === BaseType::STRING) { // @codeCoverageIgnore
                    $emptyStr = $this->builder->createStringConstant(''); // @codeCoverageIgnore
                    $this->builder->createInstruction('ret', [$emptyStr], false); // @codeCoverageIgnore
                } else { // @codeCoverageIgnore
                    $this->builder->addLine('call void @abort()', 1); // @codeCoverageIgnore
                    $this->builder->addLine('unreachable', 1); // @codeCoverageIgnore
                }
            }
        } else {
            $this->builder->addLine('call void @abort()', 1); // @codeCoverageIgnore
            $this->builder->addLine('unreachable', 1); // @codeCoverageIgnore
        }
    }

    /**
     * Collect class names defined in the user AST (to avoid double-emitting).
     *
     * @param array<\PhpParser\Node> $stmts
     * @return array<string, true>
     */
    private function collectUserClassNames(array $stmts): array
    {
        $names = [];
        $finder = new \PhpParser\NodeFinder();
        foreach ($finder->findInstanceOf($stmts, \PhpParser\Node\Stmt\Class_::class) as $classNode) {
            if ($classNode->name !== null) {
                $names[$classNode->name->toString()] = true;
            }
        }
        return $names;
    }

    /**
     * Call a function that canThrow. The call returns a result struct.
     * Extract is_err; if true, branch to catch dispatch (try context) or propagate.
     * Returns the extracted value on success.
     *
     * @param array<ValueAbstract> $args
     */
    protected function emitThrowingCall(string $funcName, array $args, BaseType $returnType, PicoHPData $pData): ValueAbstract
    {
        CompilerInvariant::check($this->ctx->function !== null);

        // If the current block is already terminated (e.g. after abort()), this is dead code
        $currentBB = $this->builder->getCurrentBasicBlock();
        if ($currentBB !== null && $currentBB->hasTerminator()) {
            return $returnType === BaseType::VOID ? new Void_() : new Constant(0, $returnType);
        }

        // Call the function — returns a result struct
        $structType = Builder::resultTypeName($returnType);
        $paramString = implode(', ', array_map(
            static fn (ValueAbstract $param): string => "{$param->getType()->toLLVM()} {$param->render()}",
            $args,
        ));
        $resultVal = new \App\PicoHP\LLVM\Value\Instruction('call', BaseType::PTR);
        $this->builder->addLine("{$resultVal->render()} = call {$structType} @{$funcName} ({$paramString})", 1);

        // Extract is_err flag
        $isErr = $this->builder->createExtractError($resultVal, $returnType);

        // Create basic blocks for error/success paths
        $count = $pData->mycount;
        $errBB = $this->ctx->function->addBasicBlock("err{$count}");
        $okBB = $this->ctx->function->addBasicBlock("ok{$count}");
        $this->builder->createBranch([$isErr, new Label($errBB->getName()), new Label($okBB->getName())]);

        // Error path
        $this->builder->setInsertPoint($errBB);
        $excPtr = $this->builder->createExtractException($resultVal, $returnType);

        if ($this->ctx->tryContext !== null) {
            // Inside a try block — store exception and branch to catch dispatch
            $this->builder->createStore($excPtr, $this->ctx->tryContext['exceptionSlot']);
            $this->builder->createBranch([$this->ctx->tryContext['catchLabel']]);
        } elseif ($this->ctx->function->canThrow) {
            // Caller also throws — propagate the error
            $callerRetType = $this->ctx->function->getReturnType()->toBase();
            $errResult = $this->builder->createResultErr($excPtr, $callerRetType);
            $callerStructType = Builder::resultTypeName($callerRetType);
            $this->builder->addLine("ret {$callerStructType} {$errResult->render()}", 1);
        } else {
            // Uncaught — abort
            $this->builder->addLine('call void @abort()', 1);
            $this->builder->addLine('unreachable', 1);
        }

        // Success path — extract the value
        $this->builder->setInsertPoint($okBB);
        if ($returnType === BaseType::VOID) {
            return new Void_();
        }

        return $this->builder->createExtractValue($resultVal, $returnType);
    }

    /**
     * Emit a throw for unimplemented stub functions. Allocates an Exception with
     * the function name as message and dispatches via value-exceptions.
     */
    // @codeCoverageIgnoreStart — only fires during directory builds with unresolved stubs
    protected function emitUnimplementedThrow(string $funcName): ValueAbstract
    {
        $typeId = $this->typeIdMap['Exception'] ?? 0;
        $objPtr = $this->builder->createObjectAlloc('Exception', $typeId);
        $typeIdPtr = $this->builder->createStructGEP('Exception', $objPtr, 0, BaseType::INT);
        $this->builder->createStore(new Constant($typeId, BaseType::INT), $typeIdPtr);
        $msgStr = $this->builder->createStringConstant("unimplemented: {$funcName}");
        $this->builder->createCall('Exception___construct', [$objPtr, $msgStr], BaseType::VOID);

        if ($this->ctx->tryContext !== null) {
            $this->builder->createStore($objPtr, $this->ctx->tryContext['exceptionSlot']);
            $this->builder->createBranch([$this->ctx->tryContext['catchLabel']]);
        } elseif ($this->ctx->function !== null && $this->ctx->function->canThrow) {
            $retType = $this->ctx->function->getReturnType()->toBase();
            $errResult = $this->builder->createResultErr($objPtr, $retType);
            $structType = Builder::resultTypeName($retType);
            $this->builder->addLine("ret {$structType} {$errResult->render()}", 1);
        } else {
            $this->builder->emitUnimplementedAbort($funcName);
            throw new \RuntimeException("unimplemented function: {$funcName}");
        }
        return new NullConstant(BaseType::PTR);
    }
    // @codeCoverageIgnoreEnd
}
