<?php

declare(strict_types=1);

namespace PicoIR;

class Instruction extends ValueAbstract
{
    protected static int $counter = 1;
    protected int $count;

    public ?BasicBlock $parent = null;
    public ?int $dbgLine = null;

    /**
     * @param string $opcode LLVM opcode or instruction kind
     * @param list<ValueAbstract> $operands Referenced values (for analysis)
     * @param BaseType $resultType Type this instruction produces
     * @param string $nameHint Used in SSA name generation
     */
    public function __construct(
        public readonly string $opcode,
        /** @var list<ValueAbstract> */
        public array $operands,
        BaseType $resultType,
        string $nameHint = '',
    ) {
        parent::__construct($resultType);
        $this->count = self::$counter++;
        $hint = $nameHint !== '' ? $nameHint : str_replace(' ', '', $opcode);
        $this->setName($hint);
    }

    /** Operand form — used when this instruction is referenced by another. */
    public function renderRef(): string
    {
        return "%{$this->getName()}{$this->count}";
    }

    /** Full defining line — called at emission time. Override in subclasses. */
    public function renderDef(): string
    {
        $type = $this->operands[0]->getType()->toLLVM();
        $ops = implode(', ', array_map(
            static fn (ValueAbstract $v): string => $v->renderRef(),
            $this->operands,
        ));
        return "{$this->renderRef()} = {$this->opcode} {$type} {$ops}";
    }

    public static function resetCounter(): void
    {
        static::$counter = 1;
    }
}
