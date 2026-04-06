<?php

declare(strict_types=1);

namespace App\PicoHP\Pass\IRGen;

use App\PicoHP\{BaseType, CompilerInvariant, PicoType};
use App\PicoHP\LLVM\{Builder, ValueAbstract};
use App\PicoHP\LLVM\Value\{Constant, Void_, Label, Param};
use App\PicoHP\SymbolTable\PicoHPData;

trait BuiltinEmitTrait
{
    protected function emitBuiltinExceptionClass(): void
    {
        if (!isset($this->classRegistry['Exception'])) {
            return;
        }
        // Struct type is emitted by emitStructDefinitionsForRegistry() — field 0 is type_id (i32), field 1 is message (ptr).

        // Exception___construct(ptr %this, ptr %message)
        $ctorFunc = $this->module->addFunction('Exception___construct', new PicoType(BaseType::VOID), [
            new PicoType(BaseType::PTR),
            new PicoType(BaseType::STRING),
        ]);
        $bb = $ctorFunc->addBasicBlock('entry');
        $this->builder->setInsertPoint($bb);
        $thisParam = new Param(0, BaseType::PTR);
        $msgParam = new Param(1, BaseType::STRING);
        $fieldPtr = $this->builder->createStructGEP('Exception', $thisParam, 1, BaseType::STRING);
        $this->builder->createStore($msgParam, $fieldPtr);
        $this->builder->createRetVoid();

        // Exception_getMessage(ptr %this) -> ptr
        $getMessageFunc = $this->module->addFunction('Exception_getMessage', new PicoType(BaseType::STRING), [
            new PicoType(BaseType::PTR),
        ]);
        $bb = $getMessageFunc->addBasicBlock('entry');
        $this->builder->setInsertPoint($bb);
        $thisParam = new Param(0, BaseType::PTR);
        $fieldPtr = $this->builder->createStructGEP('Exception', $thisParam, 1, BaseType::STRING);
        $msgVal = $this->builder->createLoad($fieldPtr);
        $this->builder->createInstruction('ret', [$msgVal], false);

        // Exception_getTraceAsString(ptr %this) -> ptr (stub: returns empty string)
        $getTraceFunc = $this->module->addFunction('Exception_getTraceAsString', new PicoType(BaseType::STRING), [
            new PicoType(BaseType::PTR),
        ]);
        $bb = $getTraceFunc->addBasicBlock('entry');
        $this->builder->setInsertPoint($bb);
        $emptyStr = $this->builder->createStringConstant('');
        $this->builder->createInstruction('ret', [$emptyStr], false);
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
        }
        return new Void_();
    }
}
