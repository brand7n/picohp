<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

use App\PicoHP\Tree\{NodeInterface, NodeTrait};
use App\PicoHP\{PicoType};

class Function_ implements NodeInterface
{
    use NodeTrait;

    protected string $name;
    protected PicoType $returnType;

    /**
     * @var array<PicoType>
     */
    protected array $params;

    /**
     * @param array<PicoType> $params
     */
    public function __construct(string $name, PicoType $returnType, array $params = [])
    {
        $this->name = $name;
        $this->params = $params;
        $this->returnType = $returnType;
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
        $params = [];
        $count = 0;
        foreach ($this->params as $param) {
            $params[] = "{$param->toBase()->toLLVM()} %{$count}";
            $count++;
        }
        $paramString = implode(', ', $params);
        $code[] = new IRLine("define dso_local {$this->returnType->toBase()->toLLVM()} @{$this->name}({$paramString}) {");
        foreach ($this->getChildren() as $bb) {
            assert($bb instanceof BasicBlock);
            $code = array_merge($code, $bb->getLines());
        }
        $code[] = new IRLine("}");
        $code[] = new IRLine();
        return $code;
    }
}
