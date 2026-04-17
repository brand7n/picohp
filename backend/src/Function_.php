<?php

declare(strict_types=1);

namespace PicoIR;

class Function_
{
    /** @var list<BasicBlock> */
    private array $blocks = [];

    /**
     * @param list<BaseType> $paramTypes
     */
    public function __construct(
        private readonly string $name,
        private readonly BaseType $returnType,
        private readonly array $paramTypes = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getReturnType(): BaseType
    {
        return $this->returnType;
    }

    public function addBasicBlock(string $name): BasicBlock
    {
        $bb = new BasicBlock($name);
        $bb->parent = $this;
        $this->blocks[] = $bb;
        return $bb;
    }

    /** @return list<BasicBlock> */
    public function getBasicBlocks(): array
    {
        return $this->blocks;
    }

    public function getEntryBlock(): BasicBlock
    {
        assert(count($this->blocks) > 0);
        return $this->blocks[0];
    }

    /** @return iterable<Instruction> */
    public function getAllInstructions(): iterable
    {
        foreach ($this->blocks as $bb) {
            foreach ($bb->getInstructions() as $inst) {
                yield $inst;
            }
        }
    }

    /** @return list<string> */
    public function render(): array
    {
        $params = [];
        foreach ($this->paramTypes as $i => $type) {
            $params[] = "{$type->toLLVM()} %{$i}";
        }
        $paramStr = implode(', ', $params);
        $retStr = $this->returnType->toLLVM();

        $lines = ["define {$retStr} @{$this->name}({$paramStr}) {"];
        foreach ($this->blocks as $bb) {
            $lines = array_merge($lines, $bb->render());
        }
        $lines[] = '}';
        $lines[] = '';
        return $lines;
    }
}
