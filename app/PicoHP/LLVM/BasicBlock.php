<?php

namespace App\PicoHP\LLVM;

use App\PicoHP\Tree\{NodeInterface, NodeTrait};

class BasicBlock implements NodeInterface
{
    use NodeTrait;

    // TODO: always end with branch or return

    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addLine(IRLine $line): void
    {
        $this->addChild($line);
    }
}
