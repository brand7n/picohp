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

    /** When true, this function returns a %result struct instead of the raw type. */
    public bool $canThrow = false;

    /** DWARF DISubprogram metadata node ID, if debug info is enabled. */
    public ?int $dbgSubprogramId = null;

    /**
     * @var array<PicoType>
     */
    protected array $params;

    /**
     * @param array<PicoType> $params
     */
    public function __construct(string $name, PicoType $returnType, array $params = [], bool $canThrow = false)
    {
        $this->name = $name;
        $this->params = $params;
        $this->returnType = $returnType;
        $this->canThrow = $canThrow;
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

    public function getReturnType(): PicoType
    {
        return $this->returnType;
    }

    /**
     * @return array<BasicBlock>
     */
    public function getBasicBlocks(): array
    {
        $blocks = [];
        foreach ($this->getChildren() as $child) {
            if ($child instanceof BasicBlock) {
                $blocks[] = $child;
            }
        }
        return $blocks;
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
        $retTypeStr = $this->canThrow
            ? Builder::resultTypeName($this->returnType->toBase())
            : $this->returnType->toBase()->toLLVM();
        $dbgSuffix = $this->dbgSubprogramId !== null ? " !dbg !{$this->dbgSubprogramId}" : '';
        $code[] = new IRLine("define dso_local {$retTypeStr} @{$this->name}({$paramString}){$dbgSuffix} {");
        foreach ($this->getChildren() as $bb) {
            \App\PicoHP\CompilerInvariant::check($bb instanceof BasicBlock);
            $code = array_merge($code, $bb->getLines());
        }
        $code[] = new IRLine("}");
        $code[] = new IRLine();
        return $code;
    }
}
