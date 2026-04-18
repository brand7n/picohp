<?php

declare(strict_types=1);

namespace PicoIR\Transform;

use PicoIR\{Module, Function_, BasicBlock, Instruction, ValueAbstract};
use PicoIR\Instruction\{
    AllocaInstruction,
    LoadInstruction,
    StoreInstruction,
    GepInstruction,
};

/**
 * Store-to-load forwarding (aka "registerization of small stack slots").
 *
 * For each alloca in a basic block:
 *   - If it has exactly one store and all loads come after that store
 *     (no intervening stores, same block), replace every load with the
 *     stored value and delete the alloca, store, and loads.
 *
 * This eliminates the alloca→store→load round-trip that the frontend
 * emits for every variable. After this pass, values flow directly from
 * definition to use — no stack traffic.
 *
 * Before:
 *   %slot = alloca ptr
 *   store ptr %0, ptr %slot
 *   %val = load ptr, ptr %slot
 *   ; ... use %val ...
 *
 * After:
 *   ; ... use %0 directly ...
 *
 * This is the same optimization LLVM's mem2reg does for the trivial
 * single-store case, and what QBE calls "registerization of small
 * stack slots."
 */
class StoreToLoadForwardingPass
{
    private int $allocasEliminated = 0;
    private int $loadsEliminated = 0;
    private int $storesEliminated = 0;

    public function run(Module $module): void
    {
        foreach ($module->getFunctions() as $fn) {
            foreach ($fn->getBasicBlocks() as $bb) {
                $this->processBlock($bb);
            }
        }
    }

    private function processBlock(BasicBlock $bb): void
    {
        // Keep running until no more changes — each pass may expose new opportunities
        $changed = true;
        while ($changed) {
            $changed = $this->forwardBlock($bb);
        }
    }

    private function forwardBlock(BasicBlock $bb): bool
    {
        $instructions = $bb->getInstructions();
        if ($instructions === []) {
            return false;
        }

        // Index allocas and their stores/loads
        $allocaInfo = $this->analyzeAllocas($instructions);

        // Find which allocas are promotable
        $toForward = [];
        foreach ($allocaInfo as $allocaRef => $info) {
            if ($info['storeCount'] !== 1) {
                continue; // multiple stores — can't trivially forward
            }
            if ($info['addressTaken']) {
                continue; // address escapes (used as GEP base, passed to call, etc.)
            }
            if ($info['loadCount'] === 0) {
                // Dead alloca — store with no loads. Mark for removal.
                $toForward[$allocaRef] = [
                    'storedValue' => $info['storedValue'],
                    'storePos' => $info['storePos'],
                    'loads' => [],
                    'allocaPos' => $info['allocaPos'],
                ];
                continue;
            }

            // All loads must come after the single store
            $storePos = $info['storePos'];
            $allAfter = true;
            foreach ($info['loadPositions'] as $loadPos) {
                if ($loadPos < $storePos) {
                    $allAfter = false;
                    break;
                }
            }
            if (!$allAfter) {
                continue;
            }

            $toForward[$allocaRef] = [
                'storedValue' => $info['storedValue'],
                'storePos' => $info['storePos'],
                'loads' => $info['loads'],
                'allocaPos' => $info['allocaPos'],
            ];
        }

        if ($toForward === []) {
            return false;
        }

        // Build replacement map: LoadInstruction → replacement ValueAbstract
        /** @var array<int, ValueAbstract> instructionIndex => replacement value */
        $replaceLoads = [];   // position => replacement value
        $deletePositions = []; // positions to delete

        foreach ($toForward as $allocaRef => $info) {
            $deletePositions[$info['allocaPos']] = true;
            $deletePositions[$info['storePos']] = true;
            $this->allocasEliminated++;
            $this->storesEliminated++;

            foreach ($info['loads'] as $loadPos => $loadInst) {
                $replaceLoads[$loadPos] = $info['storedValue'];
                $deletePositions[$loadPos] = true;
                $this->loadsEliminated++;
            }
        }

        // Build the replacement map from load instruction object → forwarded value
        /** @var \SplObjectStorage<Instruction, ValueAbstract> */
        $replacementMap = new \SplObjectStorage();
        foreach ($replaceLoads as $pos => $replacement) {
            $replacementMap[$instructions[$pos]] = $replacement;
        }

        // Rewrite operands: any instruction referencing a forwarded load
        // gets its operand replaced with the stored value
        $newInstructions = [];
        foreach ($instructions as $pos => $inst) {
            if (isset($deletePositions[$pos])) {
                continue; // skip deleted instructions
            }

            // Rewrite operands that reference eliminated loads
            $this->rewriteOperands($inst, $replacementMap);
            $newInstructions[] = $inst;
        }

        $bb->replaceInstructions($newInstructions);
        return true;
    }

    /**
     * Analyze all allocas in a block: count stores, loads, check address-taken.
     *
     * @param list<Instruction> $instructions
     * @return array<string, array{
     *     allocaPos: int,
     *     storeCount: int,
     *     storePos: int,
     *     storedValue: ?ValueAbstract,
     *     loadCount: int,
     *     loadPositions: list<int>,
     *     loads: array<int, LoadInstruction>,
     *     addressTaken: bool,
     * }>
     */
    private function analyzeAllocas(array $instructions): array
    {
        $info = [];

        // Pass 1: find allocas
        foreach ($instructions as $pos => $inst) {
            if ($inst instanceof AllocaInstruction) {
                $ref = $inst->renderRef();
                $info[$ref] = [
                    'allocaPos' => $pos,
                    'storeCount' => 0,
                    'storePos' => -1,
                    'storedValue' => null,
                    'loadCount' => 0,
                    'loadPositions' => [],
                    'loads' => [],
                    'addressTaken' => false,
                    'inst' => $inst,
                ];
            }
        }

        if ($info === []) {
            return [];
        }

        // Pass 2: classify uses of each alloca
        foreach ($instructions as $pos => $inst) {
            if ($inst instanceof StoreInstruction) {
                $ptrRef = $inst->pointer->renderRef();
                if (isset($info[$ptrRef])) {
                    $info[$ptrRef]['storeCount']++;
                    $info[$ptrRef]['storePos'] = $pos;
                    $info[$ptrRef]['storedValue'] = $inst->value;
                }
                // Also check if the stored *value* is an alloca (address escaping)
                $valRef = $inst->value->renderRef();
                if (isset($info[$valRef]) && $ptrRef !== $valRef) {
                    // The alloca's address is being stored somewhere — it escapes
                    // UNLESS it's being stored into another alloca (local-to-local copy)
                    if (!isset($info[$ptrRef])) {
                        $info[$valRef]['addressTaken'] = true;
                    }
                }
                continue;
            }

            if ($inst instanceof LoadInstruction) {
                $ptrRef = $inst->pointer->renderRef();
                if (isset($info[$ptrRef])) {
                    $info[$ptrRef]['loadCount']++;
                    $info[$ptrRef]['loadPositions'][] = $pos;
                    $info[$ptrRef]['loads'][$pos] = $inst;
                }
                continue;
            }

            // Any other use of an alloca ref means its address is taken
            // (passed to a call, used in a GEP as base, etc.)
            foreach ($inst->operands as $op) {
                $opRef = $op->renderRef();
                if (isset($info[$opRef]) && !($inst instanceof StoreInstruction) && !($inst instanceof LoadInstruction)) {
                    $info[$opRef]['addressTaken'] = true;
                }
            }
        }

        return $info;
    }

    /**
     * Rewrite an instruction's operands, replacing any reference to a
     * forwarded load with the stored value.
     *
     * @param \SplObjectStorage<Instruction, ValueAbstract> $replacementMap
     */
    private function rewriteOperands(Instruction $inst, \SplObjectStorage $replacementMap): void
    {
        // Rewrite the generic operands array
        foreach ($inst->operands as $i => $op) {
            if ($replacementMap->offsetExists($op)) {
                $inst->operands[$i] = $replacementMap[$op];
            }
        }

        // Rewrite typed fields on specific instruction subclasses
        if ($inst instanceof StoreInstruction) {
            if ($replacementMap->offsetExists($inst->value)) {
                $inst->value = $replacementMap[$inst->value];
            }
            if ($replacementMap->offsetExists($inst->pointer)) {
                $inst->pointer = $replacementMap[$inst->pointer];
            }
        } elseif ($inst instanceof LoadInstruction) {
            if ($replacementMap->offsetExists($inst->pointer)) {
                $inst->pointer = $replacementMap[$inst->pointer];
            }
        } elseif ($inst instanceof GepInstruction) {
            if ($replacementMap->offsetExists($inst->basePtr)) {
                $inst->basePtr = $replacementMap[$inst->basePtr];
            }
        } elseif ($inst instanceof \PicoIR\Instruction\BinaryOpInstruction) {
            if ($replacementMap->offsetExists($inst->lhs)) {
                $inst->lhs = $replacementMap[$inst->lhs];
            }
            if ($replacementMap->offsetExists($inst->rhs)) {
                $inst->rhs = $replacementMap[$inst->rhs];
            }
        } elseif ($inst instanceof \PicoIR\Instruction\CallInstruction) {
            foreach ($inst->args as $i => $arg) {
                if ($replacementMap->offsetExists($arg)) {
                    $inst->args[$i] = $replacementMap[$arg];
                }
            }
        } elseif ($inst instanceof \PicoIR\Instruction\BranchInstruction) {
            if ($inst->condition !== null && $replacementMap->offsetExists($inst->condition)) {
                $inst->condition = $replacementMap[$inst->condition];
            }
        } elseif ($inst instanceof \PicoIR\Instruction\SelectInstruction) {
            if ($replacementMap->offsetExists($inst->condition)) {
                $inst->condition = $replacementMap[$inst->condition];
            }
            if ($replacementMap->offsetExists($inst->trueVal)) {
                $inst->trueVal = $replacementMap[$inst->trueVal];
            }
            if ($replacementMap->offsetExists($inst->falseVal)) {
                $inst->falseVal = $replacementMap[$inst->falseVal];
            }
        } elseif ($inst instanceof \PicoIR\Instruction\ReturnInstruction) {
            if ($inst->value !== null && $replacementMap->offsetExists($inst->value)) {
                $inst->value = $replacementMap[$inst->value];
            }
        } elseif ($inst instanceof \PicoIR\Instruction\CastInstruction) {
            if ($replacementMap->offsetExists($inst->operand)) {
                $inst->operand = $replacementMap[$inst->operand];
            }
        }
    }

    public function getStats(): array
    {
        return [
            'allocas_eliminated' => $this->allocasEliminated,
            'loads_eliminated' => $this->loadsEliminated,
            'stores_eliminated' => $this->storesEliminated,
            'total_instructions_removed' => $this->allocasEliminated + $this->loadsEliminated + $this->storesEliminated,
        ];
    }

    public function printStats(): void
    {
        $total = $this->allocasEliminated + $this->loadsEliminated + $this->storesEliminated;
        echo "Store-to-load forwarding: "
            . "{$this->allocasEliminated} allocas, "
            . "{$this->storesEliminated} stores, "
            . "{$this->loadsEliminated} loads eliminated "
            . "({$total} instructions removed)\n";
    }
}
