<?php

declare(strict_types=1);

namespace PicoIR\Instruction;

use PicoIR\{BaseType, Instruction, ValueAbstract, Constant, NullConstant, Label, Void_};

// ── Arithmetic / Bitwise / Comparison ──────────────────────────────

class BinaryOpInstruction extends Instruction
{
    public function __construct(
        string $opcode,
        public ValueAbstract $lhs,
        public ValueAbstract $rhs,
        BaseType $resultType,
    ) {
        parent::__construct($opcode, [$lhs, $rhs], $resultType, $opcode);
    }

    public function renderDef(): string
    {
        $type = $this->lhs->getType()->toLLVM();
        return "{$this->renderRef()} = {$this->opcode} {$type} {$this->lhs->renderRef()}, {$this->rhs->renderRef()}";
    }
}

// ── Casts ──────────────────────────────────────────────────────────

enum CastKind: string
{
    case Zext = 'zext';
    case Trunc = 'trunc';
    case FpToSi = 'fptosi';
    case SiToFp = 'sitofp';
    case PtrToInt = 'ptrtoint';
    case IntToPtr = 'inttoptr';
}

class CastInstruction extends Instruction
{
    public function __construct(
        public readonly CastKind $kind,
        public ValueAbstract $operand,
        BaseType $fromType,
        BaseType $toType,
    ) {
        parent::__construct($kind->value, [$operand], $toType, 'cast');
    }

    public function renderDef(): string
    {
        $from = $this->operand->getType()->toLLVM();
        $to = $this->getType()->toLLVM();
        return "{$this->renderRef()} = {$this->kind->value} {$from} {$this->operand->renderRef()} to {$to}";
    }
}

// ── Select ─────────────────────────────────────────────────────────

class SelectInstruction extends Instruction
{
    public function __construct(
        public ValueAbstract $condition,
        public ValueAbstract $trueVal,
        public ValueAbstract $falseVal,
    ) {
        parent::__construct('select', [$condition, $trueVal, $falseVal], $trueVal->getType(), 'sel');
    }

    public function renderDef(): string
    {
        $type = $this->trueVal->getType()->toLLVM();
        return "{$this->renderRef()} = select i1 {$this->condition->renderRef()}, "
             . "{$type} {$this->trueVal->renderRef()}, {$type} {$this->falseVal->renderRef()}";
    }
}

// ── Memory ─────────────────────────────────────────────────────────

class AllocaInstruction extends Instruction
{
    public function __construct(
        public readonly BaseType $allocType,
        string $nameHint = 'alloca',
    ) {
        parent::__construct('alloca', [], $allocType, $nameHint);
    }

    public function renderDef(): string
    {
        return "{$this->renderRef()} = alloca {$this->allocType->toLLVM()}";
    }
}

class LoadInstruction extends Instruction
{
    public function __construct(
        public ValueAbstract $pointer,
    ) {
        // Load type comes from the pointer's type (what it points to)
        parent::__construct('load', [$pointer], $pointer->getType(), 'load');
    }

    public function renderDef(): string
    {
        $type = $this->getType()->toLLVM();
        return "{$this->renderRef()} = load {$type}, ptr {$this->pointer->renderRef()}";
    }
}

class StoreInstruction extends Instruction
{
    public function __construct(
        public ValueAbstract $value,
        public ValueAbstract $pointer,
    ) {
        parent::__construct('store', [$value, $pointer], BaseType::VOID, 'store');
    }

    public function renderRef(): string
    {
        return ''; // store has no result
    }

    public function renderDef(): string
    {
        $type = $this->value->getType()->toLLVM();
        return "store {$type} {$this->value->renderRef()}, ptr {$this->pointer->renderRef()}";
    }
}

// ── GEP ────────────────────────────────────────────────────────────

class GepInstruction extends Instruction
{
    public function __construct(
        public readonly string $structName,
        public ValueAbstract $basePtr,
        public readonly int $fieldIndex,
        BaseType $resultFieldType,
    ) {
        $idxConst = new Constant($fieldIndex, BaseType::INT);
        parent::__construct('getelementptr', [$basePtr, $idxConst], $resultFieldType, "gep_f{$fieldIndex}");
    }

    public function renderDef(): string
    {
        return "{$this->renderRef()} = getelementptr inbounds %struct.{$this->structName}, "
             . "ptr {$this->basePtr->renderRef()}, i32 0, i32 {$this->fieldIndex}";
    }
}

// ── Calls ──────────────────────────────────────────────────────────

class CallInstruction extends Instruction
{
    /**
     * @param list<ValueAbstract> $args
     */
    /**
     * @param list<ValueAbstract> $args
     */
    public function __construct(
        public readonly string $functionName,
        /** @var list<ValueAbstract> */
        public array $args,
        BaseType $returnType,
    ) {
        parent::__construct('call', $args, $returnType, 'call');
    }

    public function renderDef(): string
    {
        $argStr = implode(', ', array_map(
            static fn (ValueAbstract $v): string => "{$v->getType()->toLLVM()} {$v->renderRef()}",
            $this->args,
        ));
        if ($this->getType() === BaseType::VOID) {
            return "call void @{$this->functionName}({$argStr})";
        }
        $ret = $this->getType()->toLLVM();
        return "{$this->renderRef()} = call {$ret} @{$this->functionName}({$argStr})";
    }
}

// ── Terminators ────────────────────────────────────────────────────

class BranchInstruction extends Instruction
{
    public function __construct(
        public ?ValueAbstract $condition,
        public readonly Label $target,
        public readonly ?Label $falseTarget = null,
    ) {
        $ops = $condition !== null
            ? [$condition, $target, $falseTarget]
            : [$target];
        parent::__construct('br', array_filter($ops), BaseType::VOID, 'br');
    }

    public function renderRef(): string
    {
        return '';
    }

    public function renderDef(): string
    {
        if ($this->condition === null) {
            return "br label {$this->target->renderRef()}";
        }
        return "br i1 {$this->condition->renderRef()}, "
             . "label {$this->target->renderRef()}, "
             . "label {$this->falseTarget->renderRef()}";
    }
}

class SwitchInstruction extends Instruction
{
    /**
     * @param list<array{value: int, target: Label}> $cases
     */
    public function __construct(
        public readonly ValueAbstract $value,
        public readonly Label $defaultTarget,
        public readonly array $cases,
    ) {
        $ops = [$value, $defaultTarget];
        foreach ($cases as $c) {
            $ops[] = $c['target'];
        }
        parent::__construct('switch', $ops, BaseType::VOID, 'sw');
    }

    public function renderRef(): string
    {
        return '';
    }

    public function renderDef(): string
    {
        $casesStr = implode(' ', array_map(
            static fn (array $c): string => "i32 {$c['value']}, label {$c['target']->renderRef()}",
            $this->cases,
        ));
        return "switch i32 {$this->value->renderRef()}, label {$this->defaultTarget->renderRef()} [{$casesStr}]";
    }
}

class ReturnInstruction extends Instruction
{
    public function __construct(
        public ?ValueAbstract $value = null,
    ) {
        $ops = $value !== null ? [$value] : [];
        $type = $value !== null ? $value->getType() : BaseType::VOID;
        parent::__construct('ret', $ops, $type, 'ret');
    }

    public function renderRef(): string
    {
        return '';
    }

    public function renderDef(): string
    {
        if ($this->value === null) {
            return 'ret void';
        }
        $type = $this->value->getType()->toLLVM();
        return "ret {$type} {$this->value->renderRef()}";
    }
}

class UnreachableInstruction extends Instruction
{
    public function __construct()
    {
        parent::__construct('unreachable', [], BaseType::VOID, 'unreach');
    }

    public function renderRef(): string
    {
        return '';
    }

    public function renderDef(): string
    {
        return 'unreachable';
    }
}

// ── Raw (escape hatch) ─────────────────────────────────────────────

class RawInstruction extends Instruction
{
    /**
     * @param list<ValueAbstract> $referencedValues Values for analysis tracking
     */
    public function __construct(
        public readonly string $text,
        array $referencedValues = [],
    ) {
        parent::__construct('raw', $referencedValues, BaseType::VOID, 'raw');
    }

    public function renderRef(): string
    {
        return '';
    }

    public function renderDef(): string
    {
        return $this->text;
    }
}
