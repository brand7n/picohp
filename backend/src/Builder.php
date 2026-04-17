<?php

declare(strict_types=1);

namespace PicoIR;

use PicoIR\Instruction\{
    AllocaInstruction,
    BinaryOpInstruction,
    BranchInstruction,
    CallInstruction,
    CastInstruction,
    CastKind,
    GepInstruction,
    LoadInstruction,
    ReturnInstruction,
    SelectInstruction,
    StoreInstruction,
    SwitchInstruction,
    UnreachableInstruction,
    RawInstruction,
};

class Builder
{
    private ?BasicBlock $insertPoint = null;
    private ?int $dbgLine = null;
    private int $stringConstCount = 0;

    public function __construct(
        private readonly Module $module,
    ) {}

    public function setInsertPoint(BasicBlock $bb): void
    {
        $this->insertPoint = $bb;
    }

    public function getInsertPoint(): ?BasicBlock
    {
        return $this->insertPoint;
    }

    public function setDebugLine(?int $line): void
    {
        $this->dbgLine = $line;
    }

    private function emit(Instruction $inst): Instruction
    {
        $inst->dbgLine = $this->dbgLine;
        $this->insertPoint?->append($inst);
        return $inst;
    }

    // ── Arithmetic / Bitwise / Comparison ──────────────────────────

    public function createBinOp(string $opcode, ValueAbstract $lhs, ValueAbstract $rhs, ?BaseType $resultType = null): ValueAbstract
    {
        return $this->emit(new BinaryOpInstruction($opcode, $lhs, $rhs, $resultType ?? $lhs->getType()));
    }

    public function createAdd(ValueAbstract $lhs, ValueAbstract $rhs): ValueAbstract
    {
        $isFloat = $lhs->getType() === BaseType::FLOAT;
        return $this->createBinOp($isFloat ? 'fadd' : 'add', $lhs, $rhs);
    }

    public function createSub(ValueAbstract $lhs, ValueAbstract $rhs): ValueAbstract
    {
        $isFloat = $lhs->getType() === BaseType::FLOAT;
        return $this->createBinOp($isFloat ? 'fsub' : 'sub', $lhs, $rhs);
    }

    public function createICmpEq(ValueAbstract $lhs, ValueAbstract $rhs): ValueAbstract
    {
        return $this->createBinOp('icmp eq', $lhs, $rhs, BaseType::BOOL);
    }

    public function createICmpNe(ValueAbstract $lhs, ValueAbstract $rhs): ValueAbstract
    {
        return $this->createBinOp('icmp ne', $lhs, $rhs, BaseType::BOOL);
    }

    // ── Casts ──────────────────────────────────────────────────────

    public function createZext(ValueAbstract $val): ValueAbstract
    {
        return $this->emit(new CastInstruction(CastKind::Zext, $val, $val->getType(), BaseType::INT));
    }

    public function createPtrToInt(ValueAbstract $val): ValueAbstract
    {
        return $this->emit(new CastInstruction(CastKind::PtrToInt, $val, $val->getType(), BaseType::INT));
    }

    public function createIntToPtr(ValueAbstract $val): ValueAbstract
    {
        return $this->emit(new CastInstruction(CastKind::IntToPtr, $val, $val->getType(), BaseType::PTR));
    }

    // ── Select ─────────────────────────────────────────────────────

    public function createSelect(ValueAbstract $cond, ValueAbstract $trueVal, ValueAbstract $falseVal): ValueAbstract
    {
        return $this->emit(new SelectInstruction($cond, $trueVal, $falseVal));
    }

    // ── Memory ─────────────────────────────────────────────────────

    public function createAlloca(BaseType $type, string $nameHint = 'alloca'): AllocaInstruction
    {
        /** @var AllocaInstruction $inst */
        $inst = $this->emit(new AllocaInstruction($type, $nameHint));
        return $inst;
    }

    public function createLoad(ValueAbstract $pointer): ValueAbstract
    {
        return $this->emit(new LoadInstruction($pointer));
    }

    public function createStore(ValueAbstract $value, ValueAbstract $pointer): void
    {
        $this->emit(new StoreInstruction($value, $pointer));
    }

    // ── GEP ────────────────────────────────────────────────────────

    public function createStructGEP(string $structName, ValueAbstract $basePtr, int $fieldIndex, BaseType $fieldType): ValueAbstract
    {
        return $this->emit(new GepInstruction($structName, $basePtr, $fieldIndex, $fieldType));
    }

    // ── Calls ──────────────────────────────────────────────────────

    /**
     * @param list<ValueAbstract> $args
     */
    public function createCall(string $functionName, array $args, BaseType $returnType): ValueAbstract
    {
        $inst = new CallInstruction($functionName, $args, $returnType);
        $this->emit($inst);
        return $returnType === BaseType::VOID ? new Void_() : $inst;
    }

    // ── Object alloc (sizeof + runtime call) ───────────────────────

    public function createObjectAlloc(string $structName, int $typeId): ValueAbstract
    {
        // sizeof via null-GEP trick: ptrtoint (gep %struct, null, 1) to i64
        // For the mockup we just emit the call directly
        return $this->createCall('picohp_object_alloc', [
            new Constant(0, BaseType::INT),  // placeholder size
            new Constant($typeId, BaseType::INT),
        ], BaseType::PTR);
    }

    // ── Array / Map runtime ────────────────────────────────────────

    public function createArrayNew(): ValueAbstract
    {
        return $this->createCall('pico_array_new', [], BaseType::PTR);
    }

    public function createArrayPush(ValueAbstract $arr, ValueAbstract $val, BaseType $elemType): void
    {
        $suffix = $this->arrayFuncSuffix($elemType);
        $this->createCall("pico_array_push_{$suffix}", [$arr, $val], BaseType::VOID);
    }

    public function createArrayGet(ValueAbstract $arr, ValueAbstract $idx, BaseType $elemType): ValueAbstract
    {
        $suffix = $this->arrayFuncSuffix($elemType);
        return $this->createCall("pico_array_get_{$suffix}", [$arr, $idx], $elemType);
    }

    private function arrayFuncSuffix(BaseType $type): string
    {
        return match ($type) {
            BaseType::INT => 'int',
            BaseType::FLOAT => 'float',
            BaseType::BOOL => 'bool',
            BaseType::STRING => 'str',
            BaseType::PTR => 'ptr',
            default => throw new \RuntimeException("unsupported array element type"),
        };
    }

    // ── String constants ───────────────────────────────────────────

    public function createStringConstant(string $value): Global_
    {
        $name = ".str.lit." . $this->stringConstCount++;
        $escaped = $this->escapeString($value);
        $len = strlen($value) + 1;
        $this->module->addGlobal("@{$name} = private constant [{$len} x i8] c\"{$escaped}\\00\", align 1");
        return new Global_($name, BaseType::STRING);
    }

    private function escapeString(string $value): string
    {
        $result = '';
        for ($i = 0; $i < strlen($value); $i++) {
            $ord = ord($value[$i]);
            if ($ord < 32 || $ord > 126 || $value[$i] === '"' || $value[$i] === '\\') {
                $result .= '\\' . strtoupper(str_pad(dechex($ord), 2, '0', STR_PAD_LEFT));
            } else {
                $result .= $value[$i];
            }
        }
        return $result;
    }

    // ── Null check ─────────────────────────────────────────────────

    public function createNullCheck(ValueAbstract $val): ValueAbstract
    {
        return $this->createICmpEq($val, new NullConstant($val->getType()));
    }

    // ── Terminators ────────────────────────────────────────────────

    public function createBr(Label $target): void
    {
        $this->emit(new BranchInstruction(null, $target));
    }

    public function createCondBr(ValueAbstract $cond, Label $ifTrue, Label $ifFalse): void
    {
        $this->emit(new BranchInstruction($cond, $ifTrue, $ifFalse));
    }

    public function createRet(?ValueAbstract $value = null): void
    {
        $this->emit(new ReturnInstruction($value));
    }

    public function createRetVoid(): void
    {
        $this->emit(new ReturnInstruction());
    }

    /**
     * @param list<array{value: int, target: Label}> $cases
     */
    public function createSwitch(ValueAbstract $val, Label $default, array $cases): void
    {
        $this->emit(new SwitchInstruction($val, $default, $cases));
    }

    public function createUnreachable(): void
    {
        $this->emit(new UnreachableInstruction());
    }

    // ── Raw (escape hatch) ─────────────────────────────────────────

    /**
     * @param list<ValueAbstract> $referencedValues
     */
    public function addRaw(string $text, array $referencedValues = []): void
    {
        $this->emit(new RawInstruction($text, $referencedValues));
    }
}
