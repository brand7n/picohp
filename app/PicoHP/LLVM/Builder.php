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
        $this->addLine('define i32 @float_to_fixed(float %value, i32 %fractional_bits) {');
        $this->addLine('    %scaling_factor = shl i32 1, %fractional_bits');
        $this->addLine('    %scaling_factor_float = sitofp i32 %scaling_factor to float');
        $this->addLine('    %scaled_value = fmul float %value, %scaling_factor_float');
        $this->addLine('    %fixed_point = fptosi float %scaled_value to i32');
        $this->addLine('    ret i32 %fixed_point');
        $this->addLine('}');
        $this->addLine('define float @fixed_to_float(i32 %fixed_point, i32 %fractional_bits) {');
        $this->addLine('    %scaling_factor = shl i32 1, %fractional_bits');
        $this->addLine('    %scaling_factor_float = sitofp i32 %scaling_factor to float');
        $this->addLine('    %float_value = sitofp i32 %fixed_point to float');
        $this->addLine('    %result = fdiv float %float_value, %scaling_factor_float');
        $this->addLine('    ret float %result');
        $this->addLine('}');
        $this->addLine();
    }

    public function setInsertPoint(Function_ $function): void
    {
        $this->addLine('define i32 @'. $function->getName() . '() {');
        //$bb = new BasicBlock($function->getName());
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
        $strType = $type->value;
        $resultVal = new AllocaInst($name, $type->value);
        $this->addLine("{$resultVal->render()} = alloca {$strType}", 1);
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
        // TODO: in case of i8* lval should we cast $rval from i32
        //       use u8 instead?
        assert($lval instanceof AllocaInst || ($lval instanceof Instruction && $lval->getType() === 'i8*'));
        $type = $rval->getType();
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
            $this->addLine("{$valDbl->render()} = fpext float {$val->render()} to double", 1);
            $str = "@.str.f";
            $val = $valDbl;
        }
        $this->addLine("call i32 (ptr, ...) @printf(ptr {$str}, {$val->getType()} {$val->render()})", 1);
        return new Void_();
    }

    /**
     * @param array<ValueAbstract> $paramVals
     */
    public function createCall(string $functionName, array $paramVals, Type $returnType): ValueAbstract
    {
        $paramString = (new Collection($paramVals))
            ->map(fn ($param): string => "{$param->getType()} {$param->render()}")
            ->join(', ');
        $returnVal = new Instruction('call', $returnType->value);
        $this->addLine("{$returnVal->render()} = call {$returnType->value} @{$functionName} ({$paramString})", 1);
        return $returnVal;
    }

    public function createGetElementPtr(ValueAbstract $var, ValueAbstract $dim): ValueAbstract
    {
        $arrayType = $var->getType();
        $resultVal = new Instruction('getelementptr', 'i8*');
        $this->addLine("{$resultVal->render()} = getelementptr inbounds {$arrayType}, {$arrayType}* {$var->render()}, i64 0, {$dim->getType()} {$dim->render()}", 1);
        return $resultVal;
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
