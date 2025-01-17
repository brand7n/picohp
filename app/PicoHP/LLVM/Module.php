<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

use App\PicoHP\LLVM\Value\Instruction;
use App\PicoHP\Tree\{NodeInterface, NodeTrait};

// information about our module
class Module implements NodeInterface
{
    use NodeTrait;

    protected string $name;
    protected Builder $builder;
    protected ?Function_ $currentFunction = null;

    /**
     * @var array<IRLine>
     */
    protected array $globalLines = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->builder = new Builder($this, "arm64-apple-macosx14.0.0", "e-m:o-i64:64-i128:128-n32:64-S128");
        Instruction::resetCounter();
    }

    public function addFunction(string $name): Function_
    {
        $f = new Function_($name);
        $this->addChild($f);
        $this->currentFunction = $f;
        return $f;
    }

    public function getBuilder(): Builder
    {
        return $this->builder;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addLine(IRLine $line): void
    {
        $this->globalLines[] = $line;
    }

    /**
     * @return array<IRLine>
     */
    public function getLines(): array
    {
        $code = $this->globalLines;

        // render out functions and blocks within
        foreach ($this->getChildren() as $function) {
            assert($function instanceof Function_);
            $code = array_merge($code, $function->getLines());
        }

        return $code;
    }

    /**
     * @param resource $file
     */
    public function print($file = STDOUT): void
    {
        foreach ($this->getLines() as $line) {
            fwrite($file, $line->toString() . PHP_EOL);
        }
    }
}
/*
    {
        // $this->addLine('define void @poke(i32 %addr, i32 %value) {');
        // $this->addLine('    %ptr = inttoptr i32 %addr to ptr');
        // $this->addLine('    store i32 %value, i32* %ptr');
        // $this->addLine('    ret void');
        // $this->addLine('}');
        // $this->addLine('define i32 @peek(i32 %addr) {');
        // $this->addLine('    %ptr = inttoptr i32 %addr to ptr');
        // $this->addLine('    %val = load i32, i32* %ptr');
        // $this->addLine('    ret i32 %val');
        // $this->addLine('}');
        // $this->addLine('define i32 @float_to_fixed(float %value, i32 %fractional_bits) {');
        // $this->addLine('    %scaling_factor = shl i32 1, %fractional_bits');
        // $this->addLine('    %scaling_factor_float = sitofp i32 %scaling_factor to float');
        // $this->addLine('    %scaled_value = fmul float %value, %scaling_factor_float');
        // $this->addLine('    %fixed_point = fptosi float %scaled_value to i32');
        // $this->addLine('    ret i32 %fixed_point');
        // $this->addLine('}');
        // $this->addLine('define float @fixed_to_float(i32 %fixed_point, i32 %fractional_bits) {');
        // $this->addLine('    %scaling_factor = shl i32 1, %fractional_bits');
        // $this->addLine('    %scaling_factor_float = sitofp i32 %scaling_factor to float');
        // $this->addLine('    %float_value = sitofp i32 %fixed_point to float');
        // $this->addLine('    %result = fdiv float %float_value, %scaling_factor_float');
        // $this->addLine('    ret float %result');
        // $this->addLine('}');
        // $this->addLine();
    }
*/
