<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

class Label extends Instruction
{
    public function __construct(string $name)
    {
        parent::__construct($name, 'label');
    }

    public function render(): string
    {
        return "%{$this->getName()}";
    }
}
