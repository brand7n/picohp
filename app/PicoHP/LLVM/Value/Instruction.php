<?php

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\LLVM\ValueAbstract;

// A class representing an instruction (e.g., an arithmetic operation like addition)
class Instruction extends ValueAbstract {
    protected ValueAbstract $operand1;
    protected ValueAbstract $operand2;

    public function __construct(ValueAbstract $operand1, ValueAbstract $operand2, string $type) {
        parent::__construct($type);
        $this->operand1 = $operand1;
        $this->operand2 = $operand2;
    }

    // Represent the instruction as a string in LLVM IR format
    public function __toString(): string {
        $resultName = '%' . $this->getName();
        return "{$resultName} = add {$this->getType()} {$this->operand1}, {$this->operand2}";
    }
}
