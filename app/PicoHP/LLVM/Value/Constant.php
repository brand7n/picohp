<?php

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\LLVM\ValueAbstract;

// A class representing a constant value (e.g., integer, float)
class Constant extends ValueAbstract
{
    private int $value;

    public function __construct(int $value, string $type)
    {
        parent::__construct($type);
        $this->value = $value;
    }

    // Get the constant value
    public function getValue(): int
    {
        return $this->value;
    }

    // Represent the constant as a string in LLVM IR format
    public function render(): string
    {
        return $this->value . " " . $this->type;
    }
}
