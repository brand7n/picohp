<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\BaseType;

// A class representing an instruction (e.g., an arithmetic operation like addition)
class AllocaInst extends Instruction
{
    protected static int $counter = 1;
    protected int $count;

    public function __construct(string $name, BaseType $type)
    {
        parent::__construct($name, $type);
    }

    public function render(): string
    {
        return "%{$this->getName()}_localptr{$this->count}";
    }
}
