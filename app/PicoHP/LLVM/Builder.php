<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

use Illuminate\Support\Collection;
use App\PicoHP\LLVM\Value\{Instruction, Void_, AllocaInst};

class Builder
{
    /**
     * @var array<string>
     */
    protected array $code = [];

    public function __construct(string $triple, string $layout)
    {
        $this->addLine('; generated by picoHP');
        $this->addLine('target datalayout = "' . $layout . '"');
        $this->addLine('target triple = "' . $triple . '"');
        $this->addLine();
        $this->addLine('declare i32 @putchar(i32)');
        $this->addLine('define void @poke(i32 %addr, i32 %value) {');
        $this->addLine('    %ptr = inttoptr i32 %addr to ptr');
        $this->addLine('    store i32 %value, i32* %ptr');
        $this->addLine('    ret void');
        $this->addLine('}');
        $this->addLine('define i32 @peek(i32 %addr) {');
        $this->addLine('    %ptr = inttoptr i32 %addr to ptr');
        $this->addLine('    %val = load i32, i32* %ptr');
        $this->addLine('    ret i32 %val');
        $this->addLine('}');
        $this->addLine();
    }

    public function setInsertPoint(Function_ $function): void
    {
        $this->addLine('define i32 @'. $function->getName() . '() {');
    }

    public function endFunction(): void
    {
        $this->addLine('}');
    }

    /**
     * @param array<ValueAbstract> $operands
     */
    public function createInstruction(string $opcode, array $operands, bool $emitResult = true): ValueAbstract
    {
        $operandString = (new Collection($operands))
            ->map(fn ($operand): string => $operand->render())
            ->join(', ');
        $resultVal = new Void_();
        if ($emitResult) {
            $resultVal = new Instruction($opcode, 'i32');
            $this->addLine("{$resultVal->render()} = {$opcode} i32 {$operandString}", 1);
        } else {
            $this->addLine("{$opcode} i32 {$operandString}", 1);
        }
        return $resultVal;
    }

    public function createAlloca(string $name): ValueAbstract
    {
        $resultVal = new AllocaInst($name, 'i32');
        $this->addLine("{$resultVal->render()} = alloca i32", 1);
        return $resultVal;
    }

    public function createLoad(AllocaInst $loadptr): ValueAbstract
    {//store i32 3, ptr %ptr
        $resultVal = new Instruction("{$loadptr->getName()}_load", 'i32');
        $this->addLine("{$resultVal->render()} = load i32, i32* {$loadptr->render()}", 1);
        return $resultVal;
    }

    public function createStore(ValueAbstract $rval, ValueAbstract $lval): ValueAbstract
    {
        if (!$lval instanceof AllocaInst) {
            throw new \Exception();
        }
        $this->addLine("store i32 {$rval->render()}, i32* {$lval->render()}", 1);
        return new Void_();
    }

    protected function addLine(?string $line = null, int $indent = 0): void
    {
        if ($line === null) {
            $this->code[] = '';
            return;
        }
        $this->code[] = str_repeat(' ', $indent * 4) . $line;
    }

    /**
     * @return array<string>
     */
    public function getLines(): array
    {
        return $this->code;
    }

    /**
     * @param resource $file
     */
    public function print($file = STDOUT): void
    {
        foreach ($this->code as $line) {
            fwrite($file, $line . PHP_EOL);
        }
    }
}
/*

define i32 @foo(i32 %0, i32 %1) {
entry:
  %local_var = alloca i32
  %sum = add i32 %0, %1
  store i32 %sum, i32* %local_var
  %load = load i32, i32* %local_var
  ret i32 %load
}

%ptr = alloca i32                               ; yields ptr
store i32 3, ptr %ptr                           ; yields void
%val = load i32, ptr %ptr

#include <llvm/IR/LLVMContext.h>
#include <llvm/IR/Module.h>
#include <llvm/IR/IRBuilder.h>
#include <llvm/IR/Verifier.h>
#include <llvm/IR/Function.h>
#include <llvm/IR/BasicBlock.h>
#include <llvm/Support/raw_ostream.h>
#include <llvm/ADT/APInt.h>

using namespace llvm;

int main() {
    // Step 1: Initialize LLVM components
    LLVMContext context;
    Module* module = new Module("simple_module", context);
    IRBuilder<> builder(context);

    // Step 2: Define the function signature (int foo(int, int))
    Type* int32Type = Type::getInt32Ty(context);
    std::vector<Type*> paramTypes = { int32Type, int32Type };
    FunctionType* funcType = FunctionType::get(int32Type, paramTypes, false);

    // Step 3: Create the function
    Function* func = Function::Create(funcType, Function::ExternalLinkage, "foo", module);

    // Step 4: Create the entry basic block for the function
    BasicBlock* entry = BasicBlock::Create(context, "entry", func);
    builder.SetInsertPoint(entry);

    // Step 5: Get the function parameters
    auto args = func->arg_begin();
    Value* param1 = &*args++;
    Value* param2 = &*args;

    // Step 6: Create local variables (using stack)
    AllocaInst* localVar = builder.CreateAlloca(int32Type, nullptr, "local_var");

    // Step 7: Add the two parameters and store the result in the local variable
    Value* sum = builder.CreateAdd(param1, param2, "sum");
    builder.CreateStore(sum, localVar);

    // Step 8: Load the value from local variable (to return it)
    Value* load = builder.CreateLoad(int32Type, localVar, "load");

    // Step 9: Return the loaded value
    builder.CreateRet(load);

    // Step 10: Verify the module (optional, but useful for debugging)
    std::string errStr;
    if (verifyModule(*module, &errs())) {
        errs() << "Module verification failed!\n";
        return 1;
    }

    // Step 11: Output the IR to a string and print it
    raw_string_ostream os(errStr);
    module->print(os, nullptr);

    // Print the generated IR
    std::cout << os.str() << std::endl;

    // Clean up
    delete module;

    return 0;
}

*/
