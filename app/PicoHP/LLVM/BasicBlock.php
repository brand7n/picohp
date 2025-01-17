<?php

namespace App\PicoHP\LLVM;

use App\PicoHP\Tree\{NodeInterface, NodeTrait};

class BasicBlock implements NodeInterface
{
    use NodeTrait;

    // TODO: always end with branch or return
    protected string $name;

    /**
     * @var array<IRLine>
     */
    protected array $lines = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->lines[] = new IRLine("{$name}:");
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addLine(IRLine $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * @return array<IRLine>
     */
    public function getLines(): array
    {
        return $this->lines;
    }
}
