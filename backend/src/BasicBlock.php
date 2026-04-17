<?php

declare(strict_types=1);

namespace PicoIR;

use PicoIR\Instruction\{BranchInstruction, SwitchInstruction, ReturnInstruction, UnreachableInstruction};

class BasicBlock
{
    /** @var list<Instruction> */
    private array $instructions = [];
    public ?Function_ $parent = null;

    public function __construct(
        private readonly string $name,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function append(Instruction $inst): void
    {
        if ($this->hasTerminator()) {
            return; // don't append after a terminator
        }
        $inst->parent = $this;
        $this->instructions[] = $inst;
    }

    public function prepend(Instruction $inst): void
    {
        $inst->parent = $this;
        array_unshift($this->instructions, $inst);
    }

    /** @return list<Instruction> */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    /** @param list<Instruction> $instructions */
    public function replaceInstructions(array $instructions): void
    {
        $this->instructions = [];
        foreach ($instructions as $inst) {
            $inst->parent = $this;
            $this->instructions[] = $inst;
        }
    }

    public function hasTerminator(): bool
    {
        if ($this->instructions === []) {
            return false;
        }
        $last = end($this->instructions);
        return $last instanceof BranchInstruction
            || $last instanceof SwitchInstruction
            || $last instanceof ReturnInstruction
            || $last instanceof UnreachableInstruction;
    }

    /** @return list<string> */
    public function render(): array
    {
        $lines = ["{$this->name}:"];
        foreach ($this->instructions as $inst) {
            $def = $inst->renderDef();
            if ($def !== '') {
                $lines[] = "    {$def}";
            }
        }
        return $lines;
    }
}
