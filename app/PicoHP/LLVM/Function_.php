<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

use App\PicoHP\Tree\{NodeInterface, NodeTrait};

class Function_ implements NodeInterface
{
    use NodeTrait;

    protected string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function addBasicBlock(string $name): BasicBlock
    {
        $bb = new BasicBlock($name);
        $this->addChild($bb);
        return $bb;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<IRLine>
     */
    public function getLines(): array
    {
        $code = [];
        $code[] = new IRLine("define dso_local i32 @{$this->name}() {");
        foreach ($this->getChildren() as $bb) {
            assert($bb instanceof BasicBlock);
            $code = array_merge($code, $bb->getLines());
        }
        $code[] = new IRLine("}");
        return $code;
    }
}
