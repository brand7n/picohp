<?php

declare(strict_types=1);

namespace PicoIR\Transform;

use PicoIR\{Module, Function_, BasicBlock, Instruction, ValueAbstract};
use PicoIR\Instruction\{
    AllocaInstruction,
    BinaryOpInstruction,
    BranchInstruction,
    CallInstruction,
    CastInstruction,
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
 * Dead instruction elimination.
 *
 * Walks each function's instructions. If an instruction:
 *   1. Produces a value (has an SSA name)
 *   2. That value is never referenced by any other instruction
 *   3. The instruction has no side effects
 * Then delete it.
 *
 * Repeats until no more changes — deletion of one instruction may
 * make its operands dead too (cascading elimination).
 *
 * Side-effecting instructions that are NEVER deleted:
 *   - StoreInstruction (writes to memory)
 *   - CallInstruction (may have side effects even if result unused)
 *   - BranchInstruction, SwitchInstruction (control flow)
 *   - ReturnInstruction (control flow)
 *   - UnreachableInstruction (terminator)
 *   - RawInstruction (unknown effects)
 */
class DeadInstructionEliminationPass
{
    private int $eliminated = 0;

    public function run(Module $module): void
    {
        foreach ($module->getFunctions() as $fn) {
            $this->processFunction($fn);
        }
    }

    private function processFunction(Function_ $fn): void
    {
        $changed = true;
        while ($changed) {
            $changed = $this->eliminateDeadInFunction($fn);
        }
    }

    private function eliminateDeadInFunction(Function_ $fn): bool
    {
        // Build use map: SSA ref → number of times it's referenced as an operand
        $useCount = $this->buildUseMap($fn);

        $changed = false;

        foreach ($fn->getBasicBlocks() as $bb) {
            $instructions = $bb->getInstructions();
            $kept = [];
            $removed = false;

            foreach ($instructions as $inst) {
                $ref = $inst->renderRef();

                // Can this instruction be deleted?
                if ($ref !== ''                              // produces a value
                    && ($useCount[$ref] ?? 0) === 0          // nobody uses it
                    && !$this->hasSideEffects($inst)         // no side effects
                ) {
                    $this->eliminated++;
                    $removed = true;
                    continue; // skip — don't add to $kept
                }

                $kept[] = $inst;
            }

            if ($removed) {
                $bb->replaceInstructions($kept);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Build a map of SSA ref → use count across the entire function.
     *
     * @return array<string, int>
     */
    private function buildUseMap(Function_ $fn): array
    {
        $uses = [];

        foreach ($fn->getAllInstructions() as $inst) {
            foreach ($inst->operands as $op) {
                $ref = $op->renderRef();
                if ($ref !== '') {
                    $uses[$ref] = ($uses[$ref] ?? 0) + 1;
                }
            }

            // Also count typed fields that might not be in the generic operands array
            $this->countTypedFieldUses($inst, $uses);
        }

        return $uses;
    }

    /**
     * Count uses from typed fields on instruction subclasses.
     * Some subclasses store operands in named fields AND in the
     * generic operands array; some only in named fields.
     *
     * @param array<string, int> $uses
     */
    private function countTypedFieldUses(Instruction $inst, array &$uses): void
    {
        // CallInstruction stores args separately from operands
        if ($inst instanceof CallInstruction) {
            foreach ($inst->args as $arg) {
                $ref = $arg->renderRef();
                if ($ref !== '') {
                    $uses[$ref] = ($uses[$ref] ?? 0) + 1;
                }
            }
        }

        // StoreInstruction — value and pointer
        if ($inst instanceof StoreInstruction) {
            $this->countRef($inst->value, $uses);
            $this->countRef($inst->pointer, $uses);
        }

        // LoadInstruction — pointer
        if ($inst instanceof LoadInstruction) {
            $this->countRef($inst->pointer, $uses);
        }

        // GepInstruction — basePtr
        if ($inst instanceof GepInstruction) {
            $this->countRef($inst->basePtr, $uses);
        }

        // BinaryOpInstruction — lhs, rhs
        if ($inst instanceof BinaryOpInstruction) {
            $this->countRef($inst->lhs, $uses);
            $this->countRef($inst->rhs, $uses);
        }

        // CastInstruction — operand
        if ($inst instanceof CastInstruction) {
            $this->countRef($inst->operand, $uses);
        }

        // SelectInstruction — condition, trueVal, falseVal
        if ($inst instanceof SelectInstruction) {
            $this->countRef($inst->condition, $uses);
            $this->countRef($inst->trueVal, $uses);
            $this->countRef($inst->falseVal, $uses);
        }

        // ReturnInstruction — value
        if ($inst instanceof ReturnInstruction && $inst->value !== null) {
            $this->countRef($inst->value, $uses);
        }

        // BranchInstruction — condition
        if ($inst instanceof BranchInstruction && $inst->condition !== null) {
            $this->countRef($inst->condition, $uses);
        }

        // SwitchInstruction — value
        if ($inst instanceof SwitchInstruction) {
            $this->countRef($inst->value, $uses);
        }
    }

    /** @param array<string, int> $uses */
    private function countRef(ValueAbstract $val, array &$uses): void
    {
        $ref = $val->renderRef();
        if ($ref !== '') {
            $uses[$ref] = ($uses[$ref] ?? 0) + 1;
        }
    }

    /**
     * Does this instruction have side effects beyond producing a value?
     * If yes, it can't be deleted even if its result is unused.
     */
    private function hasSideEffects(Instruction $inst): bool
    {
        // These always have side effects
        if ($inst instanceof StoreInstruction) return true;
        if ($inst instanceof CallInstruction) return true;   // conservative: any call may have effects
        if ($inst instanceof BranchInstruction) return true;
        if ($inst instanceof SwitchInstruction) return true;
        if ($inst instanceof ReturnInstruction) return true;
        if ($inst instanceof UnreachableInstruction) return true;
        if ($inst instanceof RawInstruction) return true;

        // These are pure — safe to delete if unused
        // AllocaInstruction, BinaryOpInstruction, CastInstruction,
        // SelectInstruction, GepInstruction, LoadInstruction
        return false;
    }

    public function getEliminated(): int
    {
        return $this->eliminated;
    }

    public function printStats(): void
    {
        echo "Dead instruction elimination: {$this->eliminated} instructions removed\n";
    }
}
