<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\BaseType;

class Label extends Instruction
{
    public function __construct(string $name)
    {
        parent::__construct($name, BaseType::LABEL);
    }

    public function render(): string
    {
        return "%{$this->getName()}";
    }
}
