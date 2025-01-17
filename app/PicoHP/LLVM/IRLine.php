<?php

namespace App\PicoHP\LLVM;

use App\PicoHP\Tree\{NodeInterface, NodeTrait};

class IRLine implements NodeInterface
{
    use NodeTrait;

    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function toString(): string
    {
        return $this->name;
    }
}
