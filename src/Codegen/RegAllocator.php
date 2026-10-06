<?php

declare(strict_types=1);

namespace PicoIR\Codegen;

use PicoIR\{BasicBlock, Instruction, ValueAbstract, BaseType};
use PicoIR\{Constant, NullConstant, Label, Void_};
use PicoIR\Instruction\CallInstruction;

/**
 * Linear scan register allocator (per basic block).
 *
 * Algorithm:
 *   1. Compute live intervals [def, last-use] for each SSA value
 *   2. Exclude intervals that cross a call site (must use frame)
 *   3. Exclude single-use values (register overhead > benefit)
 *   4. Sort by start position
 *   5. Walk intervals: assign a free register, or spill the one ending latest
 *
 * Allocated registers: x10-x15 (caller-saved, not used for args or scratch).
 */
class RegAllocator
{
    /** @var list<string> */
    public const REGS = ['x10', 'x11', 'x12', 'x13', 'x14', 'x15'];

    /**
     * @return array<string, string> SSA ref => register name
     */
    public function allocate(BasicBlock $bb): array
    {
        $instructions = $bb->getInstructions();
        if ($instructions === []) {
            return [];
        }

        // Step 1: live intervals
        $intervals = [];  // ref => [start, end]
        $useCounts = [];  // ref => int

        foreach ($instructions as $pos => $inst) {
            $ref = $inst->renderRef();
            if ($ref !== '' && !isset($intervals[$ref])) {
                $intervals[$ref] = [$pos, $pos];
            }

            foreach ($this->usedRefs($inst) as $usedRef) {
                $useCounts[$usedRef] = ($useCounts[$usedRef] ?? 0) + 1;
                if (!isset($intervals[$usedRef])) {
                    $intervals[$usedRef] = [0, $pos];
                } else {
                    $intervals[$usedRef][1] = max($intervals[$usedRef][1], $pos);
                }
            }
        }

        // Step 2: find call positions
        $callPositions = [];
        foreach ($instructions as $pos => $inst) {
            if ($inst instanceof CallInstruction) {
                $callPositions[] = $pos;
            }
        }

        // Step 3: exclude intervals crossing calls or with single use
        foreach ($intervals as $ref => [$start, $end]) {
            if (($useCounts[$ref] ?? 0) < 2) {
                unset($intervals[$ref]);
                continue;
            }
            foreach ($callPositions as $cp) {
                if ($start < $cp && $end > $cp) {
                    unset($intervals[$ref]);
                    break;
                }
            }
        }

        // Step 4: sort by start
        uasort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);

        // Step 5: linear scan
        $assignments = [];
        $active = [];     // ref => [reg, end]
        $free = array_reverse(self::REGS);

        foreach ($intervals as $ref => [$start, $end]) {
            // Expire finished intervals
            $keep = [];
            foreach ($active as $aRef => [$aReg, $aEnd]) {
                if ($aEnd < $start) {
                    $free[] = $aReg;
                } else {
                    $keep[$aRef] = [$aReg, $aEnd];
                }
            }
            $active = $keep;

            if (count($free) > 0) {
                $reg = array_pop($free);
                $assignments[$ref] = $reg;
                $active[$ref] = [$reg, $end];
            } else {
                // Spill: evict the interval ending latest
                $spillRef = null;
                $spillEnd = -1;
                foreach ($active as $aRef => [$aReg, $aEnd]) {
                    if ($aEnd > $spillEnd) {
                        $spillEnd = $aEnd;
                        $spillRef = $aRef;
                    }
                }
                if ($spillRef !== null && $spillEnd > $end) {
                    $reg = $active[$spillRef][0];
                    unset($active[$spillRef], $assignments[$spillRef]);
                    $assignments[$ref] = $reg;
                    $active[$ref] = [$reg, $end];
                }
            }
        }

        return $assignments;
    }

    /** @return list<string> SSA refs used as operands */
    private function usedRefs(Instruction $inst): array
    {
        $refs = [];
        foreach ($inst->operands as $op) {
            if ($op instanceof Constant || $op instanceof NullConstant
                || $op instanceof Label || $op instanceof Void_) {
                continue;
            }
            $ref = $op->renderRef();
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }
        return $refs;
    }
}
