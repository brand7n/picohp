<?php

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\LLVM\ValueAbstract;

// A class representing an instruction (e.g., an arithmetic operation like addition)
class Instruction extends ValueAbstract
{
    /**
     * @var array<ValueAbstract>
     */
    protected array $operands;

    protected string $opcode;

    /**
     * @param array<ValueAbstract> $operands
     */
    public function __construct(string $opcode, array $operands, string $type)
    {
        parent::__construct($type);
        $this->operands = $operands;
        $this->opcode = $opcode;
    }

    // Represent the instruction as a string in LLVM IR format
    public function __toString(): string
    {
        $resultName = '%' . $this->getName();
        $operandString = join(', ', $this->operands);
        return "{$resultName} = {$this->opcode} {$this->getType()} {$operandString}";
    }
}
