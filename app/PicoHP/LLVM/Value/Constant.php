<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\LLVM\{Type, ValueAbstract};

// A class representing a constant value (e.g., integer, float)
class Constant extends ValueAbstract
{
    private int|float $value;

    public function __construct(float|int $value, Type $type)
    {
        parent::__construct($type->value);
        $this->value = $value;
    }

    // Get the constant value
    protected function getValue(): int|float
    {
        return $this->value;
    }

    // Represent the constant as a string in LLVM IR format
    public function render(): string
    {
        return (string)$this->value;
    }
}
