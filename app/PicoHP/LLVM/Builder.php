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
        $this->addLine('@.str.d = private constant [4 x i8] c"%d\0A\00", align 1');
        $this->addLine('@.str.f = private constant [4 x i8] c"%f\0A\00", align 1');
        $this->addLine();
        $this->addLine('declare i32 @printf(ptr, ...)');
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
    public function createInstruction(string $opcode, array $operands, bool $emitResult = true, Type $resultType = Type::INT): ValueAbstract
    {
        $operandString = (new Collection($operands))
            ->map(fn ($operand): string => $operand->render())
            ->join(', ');
        $resultVal = new Void_();
        if ($emitResult) {
            $resultVal = new Instruction($opcode, $resultType->value);
            $this->addLine("{$resultVal->render()} = {$opcode} i32 {$operandString}", 1);
        } else {
            $this->addLine("{$opcode} i32 {$operandString}", 1);
        }
        return $resultVal;
    }

    public function createAlloca(string $name, Type $type): ValueAbstract
    {
        $resultVal = new AllocaInst($name, $type->value);
        $this->addLine("{$resultVal->render()} = alloca {$type->value}", 1);
        return $resultVal;
    }

    public function createLoad(AllocaInst $loadptr): ValueAbstract
    {
        $type = $loadptr->getType();
        $resultVal = new Instruction("{$loadptr->getName()}_load", $type);
        $this->addLine("{$resultVal->render()} = load {$type}, {$type}* {$loadptr->render()}", 1);
        return $resultVal;
    }

    public function createStore(ValueAbstract $rval, ValueAbstract $lval): ValueAbstract
    {
        assert($lval instanceof AllocaInst);
        $type = $lval->getType();
        $this->addLine("store {$type} {$rval->render()}, {$type}* {$lval->render()}", 1);
        return new Void_();
    }

    public function createFpToSi(ValueAbstract $val): ValueAbstract
    {
        $resultVal = new Instruction("cast", 'i32');
        $this->addLine("{$resultVal->render()} = fptosi float {$val->render()} to i32", 1);
        return $resultVal;
    }

    public function createSiToFp(ValueAbstract $val): ValueAbstract
    {
        $resultVal = new Instruction("cast", 'float');
        $this->addLine("{$resultVal->render()} = sitofp i32 {$val->render()} to float", 1);
        return $resultVal;
    }

    public function createZext(ValueAbstract $val): ValueAbstract
    {
        $resultVal = new Instruction("cast", 'i32');
        $this->addLine("{$resultVal->render()} = zext i1 {$val->render()} to i32", 1);
        return $resultVal;
    }

    public function createCallPrintf(ValueAbstract $val): ValueAbstract
    {
        $str = "@.str.d";
        if ($val->getType() === 'float') {
            $valDbl = new Instruction('fpext', 'double');
            $this->addLine("{$valDbl->render()} = fpext float {$val->render()} to double");
            $str = "@.str.f";
            $val = $valDbl;
        }
        $this->addLine("call i32 (ptr, ...) @printf(ptr {$str}, {$val->getType()} {$val->render()})", 1);
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
