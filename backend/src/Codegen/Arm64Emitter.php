<?php

declare(strict_types=1);

namespace PicoIR\Codegen;

use PicoIR\{Module, Function_, BasicBlock, Instruction, BaseType, ValueAbstract};
use PicoIR\{Constant, NullConstant, Param, Global_, Label, Void_};
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
    RawInstruction,
    SelectInstruction,
    StoreInstruction,
    SwitchInstruction,
    UnreachableInstruction,
};

/**
 * ARM64 (AArch64) code emitter using stack-machine style reduction.
 *
 * Every SSA value is assigned a frame slot (8 bytes). Expression evaluation
 * follows the classic stack-machine pattern:
 *   1. Load operands from their frame slots into scratch registers
 *   2. Compute the result
 *   3. Store the result back to this instruction's frame slot
 *
 * No register allocation. All intermediates live on the stack frame.
 *
 * Frame layout (growing downward from x29/fp):
 *   [x29 + 16]  saved lr
 *   [x29 + 8]   saved fp
 *   [x29]       current frame pointer
 *   [x29 - 8]   slot 0
 *   [x29 - 16]  slot 1
 *   ...
 *   [sp]         bottom (16-byte aligned)
 *
 * Alloca slots hold the variable's value directly (the "pointer" is the
 * slot's address). All other slots hold the instruction's SSA result.
 * Struct fields are simplified to 8 bytes each.
 */
class Arm64Emitter
{
    private const SLOT_SIZE = 8;
    private const FIELD_SIZE = 8;

    /** @var array<string, int> SSA ref => slot index */
    private array $slots = [];

    /** @var array<string, true> SSA refs that are alloca instructions */
    private array $allocaRefs = [];

    /** @var array<string, int> SSA ref => frame offset of stack-promoted struct data */
    private array $structSlots = [];

    private int $nextSlot = 0;
    private int $frameSize = 0;
    private string $fnName = '';

    /** @var list<string> */
    private array $lines = [];

    private ?Module $module = null;

    public function emit(Module $module): string
    {
        $this->lines = [];
        $this->module = $module;

        $this->line("; ARM64 assembly -- PicoIR stack-machine codegen");
        $this->line("; Every SSA value lives in a frame slot. No register allocation.");
        $this->line(".section __TEXT,__text,regular,pure_instructions");
        $this->line(".p2align 2");

        foreach ($module->getFunctions() as $fn) {
            $this->emitFunction($fn);
        }

        return implode("\n", $this->lines) . "\n";
    }

    private function emitFunction(Function_ $fn): void
    {
        $this->slots = [];
        $this->allocaRefs = [];
        $this->structSlots = [];
        $this->nextSlot = 0;
        $this->fnName = $fn->getName();

        $this->assignSlots($fn);

        $this->frameSize = (($this->nextSlot * self::SLOT_SIZE + 15) & ~15);
        if ($this->frameSize === 0) {
            $this->frameSize = 16;
        }

        $this->line("");
        $this->line(".globl _{$this->fnName}");
        $this->line(".p2align 2");
        $this->line("_{$this->fnName}:");

        $this->comment("prologue: {$this->nextSlot} slots, {$this->frameSize} bytes");
        $this->inst("stp x29, x30, [sp, #-16]!");
        $this->inst("mov x29, sp");
        $this->inst("sub sp, sp, #{$this->frameSize}");

        foreach ($fn->getParamTypes() as $i => $type) {
            $ref = "%{$i}";
            if (isset($this->slots[$ref])) {
                $reg = $type === BaseType::FLOAT ? "d{$i}" : "x{$i}";
                $this->comment("spill param {$i}");
                $this->storeSlot($ref, $reg);
            }
        }

        $blocks = $fn->getBasicBlocks();
        foreach ($blocks as $idx => $bb) {
            if ($idx > 0) {
                $this->line($this->bbLabel($bb->getName()) . ":");
            }
            foreach ($bb->getInstructions() as $inst) {
                $this->emitInstruction($inst);
            }
        }
    }

    private function assignSlots(Function_ $fn): void
    {
        foreach ($fn->getParamTypes() as $i => $_) {
            $this->slots["%{$i}"] = $this->nextSlot++;
        }

        foreach ($fn->getAllInstructions() as $inst) {
            $ref = $inst->renderRef();
            if ($ref === '') {
                continue;
            }

            if ($inst instanceof \PicoIR\Transform\StackAllocMarker) {
                // Slot for the pointer value
                $this->slots[$ref] = $this->nextSlot++;
                // Contiguous slots for struct fields
                $numFields = $this->countStructFields($this->module, $inst->structName);
                $dataStart = $this->nextSlot;
                $this->nextSlot += $numFields;
                $this->structSlots[$ref] = ($dataStart + $numFields) * self::SLOT_SIZE;
                continue;
            }

            $this->slots[$ref] = $this->nextSlot++;

            if ($inst instanceof AllocaInstruction) {
                $this->allocaRefs[$ref] = true;
            }
        }
    }

    /** Scan all GEPs in the module to determine how many fields a struct has. */
    private function countStructFields(Module $module, string $structName): int
    {
        $maxField = 0;
        foreach ($module->getFunctions() as $fn) {
            foreach ($fn->getAllInstructions() as $inst) {
                if ($inst instanceof GepInstruction && $inst->structName === $structName) {
                    $maxField = max($maxField, $inst->fieldIndex);
                }
            }
        }
        return $maxField + 1;
    }

    // -- Instruction dispatch --

    private function emitInstruction(Instruction $inst): void
    {
        if ($inst instanceof \PicoIR\Transform\StackAllocMarker) {
            $ref = $inst->renderRef();
            $dataOff = $this->structSlots[$ref];
            $this->comment("stack-promoted alloca {$ref} (%struct.{$inst->structName}, {$dataOff} bytes into frame)");
            $this->inst("sub x0, x29, #{$dataOff}");
            $this->storeSlot($ref, 'x0');
            return;
        }
        if ($inst instanceof AllocaInstruction) {
            $this->comment("alloca {$inst->renderRef()}");
            return;
        }
        if ($inst instanceof LoadInstruction) {
            $this->emitLoad($inst);
            return;
        }
        if ($inst instanceof StoreInstruction) {
            $this->emitStore($inst);
            return;
        }
        if ($inst instanceof BinaryOpInstruction) {
            $this->emitBinOp($inst);
            return;
        }
        if ($inst instanceof CastInstruction) {
            $this->emitCast($inst);
            return;
        }
        if ($inst instanceof SelectInstruction) {
            $this->emitSelect($inst);
            return;
        }
        if ($inst instanceof GepInstruction) {
            $this->emitGep($inst);
            return;
        }
        if ($inst instanceof CallInstruction) {
            $this->emitCall($inst);
            return;
        }
        if ($inst instanceof BranchInstruction) {
            $this->emitBranch($inst);
            return;
        }
        if ($inst instanceof ReturnInstruction) {
            $this->emitReturn($inst);
            return;
        }
        if ($inst instanceof SwitchInstruction) {
            $this->emitSwitch($inst);
            return;
        }
        if ($inst instanceof UnreachableInstruction) {
            $this->comment("unreachable");
            $this->inst("brk #1");
            return;
        }
        if ($inst instanceof RawInstruction) {
            $this->comment("raw: {$inst->text}");
            return;
        }
    }

    // -- Load / Store --

    private function emitLoad(LoadInstruction $inst): void
    {
        $ref = $inst->renderRef();
        $ptrRef = $inst->pointer->renderRef();
        $isFloat = $inst->getType() === BaseType::FLOAT;
        $r = $isFloat ? 'd0' : 'x0';

        if (isset($this->allocaRefs[$ptrRef])) {
            $this->comment("load {$ref} <- alloca {$ptrRef}");
            $this->loadSlot($ptrRef, $r);
        } else {
            $this->comment("load {$ref} <- *{$ptrRef}");
            $this->loadSlot($ptrRef, 'x0');
            $this->inst($isFloat ? "ldr d0, [x0]" : "ldr x0, [x0]");
        }
        $this->storeSlot($ref, $r);
    }

    private function emitStore(StoreInstruction $inst): void
    {
        $ptrRef = $inst->pointer->renderRef();
        $isFloat = $inst->value->getType() === BaseType::FLOAT;

        if (isset($this->allocaRefs[$ptrRef])) {
            $this->comment("store -> alloca {$ptrRef}");
            $this->loadVal($inst->value, 'x0', 'd0');
            $this->storeSlot($ptrRef, $isFloat ? 'd0' : 'x0');
        } else {
            $this->comment("store -> *{$ptrRef}");
            $this->loadSlot($ptrRef, 'x1');
            $this->loadVal($inst->value, 'x0', 'd0');
            $this->inst($isFloat ? "str d0, [x1]" : "str x0, [x1]");
        }
    }

    // -- Binary operations --

    private function emitBinOp(BinaryOpInstruction $inst): void
    {
        $ref = $inst->renderRef();
        $lhsType = $inst->lhs->getType();
        $isFloat = $lhsType === BaseType::FLOAT;
        // w = 32-bit (int/bool), x = 64-bit (ptr)
        $r = ($lhsType === BaseType::PTR || $lhsType === BaseType::STRING) ? 'x' : 'w';

        $this->comment("{$inst->opcode} {$ref}");
        $this->loadVal($inst->lhs, 'x0', 'd0');
        $this->loadVal($inst->rhs, 'x1', 'd1');

        if (str_starts_with($inst->opcode, 'icmp')) {
            $this->inst("cmp {$r}0, {$r}1");
            $cond = match ($inst->opcode) {
                'icmp eq' => 'eq', 'icmp ne' => 'ne',
                'icmp slt' => 'lt', 'icmp sgt' => 'gt',
                'icmp sle' => 'le', 'icmp sge' => 'ge',
                default => 'eq',
            };
            $this->inst("cset w0, {$cond}");
            $this->storeSlot($ref, 'x0');
            return;
        }

        if (str_starts_with($inst->opcode, 'fcmp')) {
            $this->inst("fcmp d0, d1");
            $cond = match ($inst->opcode) {
                'fcmp oeq' => 'eq', 'fcmp one' => 'ne',
                'fcmp olt' => 'mi', 'fcmp ogt' => 'gt',
                default => 'eq',
            };
            $this->inst("cset w0, {$cond}");
            $this->storeSlot($ref, 'x0');
            return;
        }

        if ($isFloat) {
            $this->inst(match ($inst->opcode) {
                'fadd' => "fadd d0, d0, d1",
                'fsub' => "fsub d0, d0, d1",
                'fmul' => "fmul d0, d0, d1",
                'fdiv' => "fdiv d0, d0, d1",
                default => "brk #1",
            });
            $this->storeSlot($ref, 'd0');
            return;
        }

        // Integer / pointer arithmetic
        match ($inst->opcode) {
            'add'  => $this->inst("add {$r}0, {$r}0, {$r}1"),
            'sub'  => $this->inst("sub {$r}0, {$r}0, {$r}1"),
            'mul'  => $this->inst("mul {$r}0, {$r}0, {$r}1"),
            'sdiv' => $this->inst("sdiv {$r}0, {$r}0, {$r}1"),
            'srem' => $this->inst("sdiv {$r}2, {$r}0, {$r}1") ?? $this->inst("msub {$r}0, {$r}2, {$r}1, {$r}0"),
            'and'  => $this->inst("and {$r}0, {$r}0, {$r}1"),
            'or'   => $this->inst("orr {$r}0, {$r}0, {$r}1"),
            'xor'  => $this->inst("eor {$r}0, {$r}0, {$r}1"),
            'shl'  => $this->inst("lsl {$r}0, {$r}0, {$r}1"),
            'ashr' => $this->inst("asr {$r}0, {$r}0, {$r}1"),
            default => $this->comment("TODO: binop {$inst->opcode}"),
        };
        $this->storeSlot($ref, 'x0');
    }

    // -- Casts --

    private function emitCast(CastInstruction $inst): void
    {
        $ref = $inst->renderRef();
        $this->comment("cast {$inst->kind->value} {$ref}");
        $this->loadVal($inst->operand, 'x0', 'd0');

        match ($inst->kind) {
            CastKind::Zext     => null,
            CastKind::Trunc    => $this->inst("and w0, w0, #1"),
            CastKind::PtrToInt => null,
            CastKind::IntToPtr => null,
            CastKind::SiToFp   => $this->inst("scvtf d0, w0"),
            CastKind::FpToSi   => $this->inst("fcvtzs w0, d0"),
        };

        $this->storeSlot($ref, $inst->kind === CastKind::SiToFp ? 'd0' : 'x0');
    }

    // -- Select --

    private function emitSelect(SelectInstruction $inst): void
    {
        $ref = $inst->renderRef();
        $isFloat = $inst->trueVal->getType() === BaseType::FLOAT;
        $this->comment("select {$ref}");

        $this->loadVal($inst->condition, 'x0', 'd0');
        $this->inst("cmp w0, #0");
        $this->loadVal($inst->trueVal, 'x1', 'd0');
        $this->loadVal($inst->falseVal, 'x2', 'd1');

        if ($isFloat) {
            $this->inst("fcsel d0, d0, d1, ne");
            $this->storeSlot($ref, 'd0');
        } else {
            $this->inst("csel x0, x1, x2, ne");
            $this->storeSlot($ref, 'x0');
        }
    }

    // -- GEP --

    private function emitGep(GepInstruction $inst): void
    {
        $ref = $inst->renderRef();
        $offset = $inst->fieldIndex * self::FIELD_SIZE;
        $this->comment("gep {$ref} = {$inst->structName}[{$inst->fieldIndex}]");

        $this->loadVal($inst->basePtr, 'x0', 'd0');
        if ($offset > 0) {
            $this->inst("add x0, x0, #{$offset}");
        }
        $this->storeSlot($ref, 'x0');
    }

    // -- Call --

    private function emitCall(CallInstruction $inst): void
    {
        $ref = $inst->renderRef();
        $this->comment("call @{$inst->functionName}");

        // Load each arg directly into its target register (x0-x7 / d0-d7).
        // loadVal only touches the target register, so no clobber conflicts.
        foreach ($inst->args as $i => $arg) {
            $isFloat = $arg->getType() === BaseType::FLOAT;
            $this->loadVal($arg, "x{$i}", "d{$i}");
        }

        $this->inst("bl _{$inst->functionName}");

        if ($inst->getType() !== BaseType::VOID && $ref !== '') {
            $reg = $inst->getType() === BaseType::FLOAT ? 'd0' : 'x0';
            $this->storeSlot($ref, $reg);
        }
    }

    // -- Branch --

    private function emitBranch(BranchInstruction $inst): void
    {
        if ($inst->condition === null) {
            $this->comment("br -> {$inst->target->getName()}");
            $this->inst("b " . $this->bbLabel($inst->target->getName()));
        } else {
            $this->comment("br cond -> {$inst->target->getName()} / {$inst->falseTarget->getName()}");
            $this->loadVal($inst->condition, 'x0', 'd0');
            $this->inst("cbnz w0, " . $this->bbLabel($inst->target->getName()));
            $this->inst("b " . $this->bbLabel($inst->falseTarget->getName()));
        }
    }

    // -- Return --

    private function emitReturn(ReturnInstruction $inst): void
    {
        if ($inst->value !== null) {
            $this->comment("return");
            $this->loadVal($inst->value, 'x0', 'd0');
        } else {
            $this->comment("return void");
        }
        $this->inst("add sp, sp, #{$this->frameSize}");
        $this->inst("ldp x29, x30, [sp], #16");
        $this->inst("ret");
    }

    // -- Switch --

    private function emitSwitch(SwitchInstruction $inst): void
    {
        $this->comment("switch");
        $this->loadVal($inst->value, 'x0', 'd0');
        foreach ($inst->cases as $case) {
            $this->inst("cmp w0, #{$case['value']}");
            $this->inst("b.eq " . $this->bbLabel($case['target']->getName()));
        }
        $this->inst("b " . $this->bbLabel($inst->defaultTarget->getName()));
    }

    // -- Stack-machine core: value load / slot store --

    /**
     * Load a PicoIR value into a register (the "push" side of the stack machine).
     */
    private function loadVal(ValueAbstract $val, string $intReg = 'x0', string $floatReg = 'd0'): void
    {
        if ($val instanceof Constant) {
            if ($val->getType() === BaseType::FLOAT) {
                $fval = (float) $val->value;
                if ($fval == 0.0) {
                    $this->inst("fmov {$floatReg}, xzr");
                } else {
                    /** @var int $bits */
                    $bits = unpack('Q', pack('d', $fval))[1];
                    $this->movImm64('x9', $bits);
                    $this->inst("fmov {$floatReg}, x9");
                }
            } else {
                $this->inst("mov {$intReg}, #{$val->value}");
            }
            return;
        }

        if ($val instanceof NullConstant) {
            $this->inst("mov {$intReg}, #0");
            return;
        }

        if ($val instanceof Global_) {
            $name = $val->getName();
            $this->inst("adrp {$intReg}, _{$name}@PAGE");
            $this->inst("add {$intReg}, {$intReg}, _{$name}@PAGEOFF");
            return;
        }

        if ($val instanceof Void_) {
            return;
        }

        // SSA reference: load from its frame slot
        $ref = $val->renderRef();
        $isFloat = $val->getType() === BaseType::FLOAT;
        $this->loadSlot($ref, $isFloat ? $floatReg : $intReg);
    }

    /** Load a frame slot into a register. */
    private function loadSlot(string $ref, string $reg = 'x0'): void
    {
        $off = $this->slotOff($ref);
        $this->inst("ldr {$reg}, [x29, #-{$off}]");
    }

    /** Store a register into a frame slot. */
    private function storeSlot(string $ref, string $reg = 'x0'): void
    {
        $off = $this->slotOff($ref);
        $this->inst("str {$reg}, [x29, #-{$off}]");
    }

    /** Build a 64-bit immediate in a register via movz/movk. */
    private function movImm64(string $reg, int $value): void
    {
        $this->inst(sprintf("movz %s, #0x%x", $reg, $value & 0xFFFF));
        for ($shift = 16; $shift < 64; $shift += 16) {
            $chunk = ($value >> $shift) & 0xFFFF;
            if ($chunk !== 0) {
                $this->inst(sprintf("movk %s, #0x%x, lsl #%d", $reg, $chunk, $shift));
            }
        }
    }

    private function slotOff(string $ref): int
    {
        if (!isset($this->slots[$ref])) {
            throw new \RuntimeException("Arm64Emitter: no slot for {$ref}");
        }
        return ($this->slots[$ref] + 1) * self::SLOT_SIZE;
    }

    private function bbLabel(string $name): string
    {
        return ".L{$this->fnName}_{$name}";
    }

    private function line(string $text): void
    {
        $this->lines[] = $text;
    }

    private function inst(string $text): void
    {
        $this->lines[] = "    {$text}";
    }

    private function comment(string $text): void
    {
        $this->lines[] = "    ; {$text}";
    }
}
