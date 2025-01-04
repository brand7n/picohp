<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

use App\PicoHP\LLVM\Value\Instruction;

// information about our module
class Module
{
    protected string $name;
    protected Builder $builder;
    protected ?Function_ $currentFunction = null;

    /**
     * @var array<string, Function_>
     */
    protected array $functions = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->builder = new Builder("arm64-apple-macosx14.0.0", "e-m:o-i64:64-i128:128-n32:64-S128");
        Instruction::resetCounter();
    }

    public function addFunction(Function_ $function): void
    {
        $this->functions[$function->getName()] = $function;
        $this->currentFunction = $function;
    }

    public function getBuilder(): Builder
    {
        return $this->builder;
    }

    /**
     * @param resource $file
     */
    public function print($file = STDOUT): void
    {
        if ($this->currentFunction !== null) {
            $this->builder->endFunction();
        }
        $this->builder->print($file);
    }
}
