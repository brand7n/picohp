<?php

declare(strict_types=1);

namespace PicoIR\Analysis;

use PicoIR\{Module, Function_, Instruction, ValueAbstract};
use PicoIR\Instruction\{
    CallInstruction,
    StoreInstruction,
    GepInstruction,
    LoadInstruction,
    AllocaInstruction,
    ReturnInstruction,
};

enum EscapeState: int
{
    case NoEscape = 0;
    case ArgEscape = 1;
    case GlobalEscape = 2;

    public function join(self $other): self
    {
        return $this->value >= $other->value ? $this : $other;
    }
}

class FunctionSummary
{
    /** @var array<string, EscapeState> allocSiteRef => state */
    public array $allocSites = [];

    /** @var array<int, EscapeState> paramIndex => state */
    public array $paramStates = [];

    /**
     * param X flows into param Y (stored into Y's field).
     * @var array<int, list<int>>
     */
    public array $paramFlowsToParam = [];

    /** @var list<int> param indices that flow to return */
    public array $paramFlowsToReturn = [];

    public int $paramCount = 0;
}

/**
 * Tracks the origin of SSA values within a function.
 */
class ValueTracker
{
    /** @var array<string, true> known allocation site refs */
    private array $allocRefs = [];
    /** @var array<string, string> alloca slot ref => allocSite ref stored in it */
    public array $slotToAlloc = [];
    /** @var array<string, int> alloca slot ref => param index stored in it */
    public array $slotToParam = [];

    public function registerAlloc(string $ref): void
    {
        $this->allocRefs[$ref] = true;
    }

    public function resolveToAlloc(ValueAbstract $val): ?string
    {
        $ref = $val->renderRef();
        if (isset($this->allocRefs[$ref])) {
            return $ref;
        }
        if ($val instanceof LoadInstruction) {
            $slot = $val->pointer->renderRef();
            return $this->slotToAlloc[$slot] ?? null;
        }
        return null;
    }

    public function resolveToParam(ValueAbstract $val): ?int
    {
        if ($val instanceof \PicoIR\Param) {
            return $val->index;
        }
        if ($val instanceof LoadInstruction) {
            $slot = $val->pointer->renderRef();
            return $this->slotToParam[$slot] ?? null;
        }
        return null;
    }
}

class EscapeAnalysisPass
{
    /** @var array<string, FunctionSummary> */
    private array $summaries = [];

    public function run(Module $module): void
    {
        // Bottom-up: analyze callees before callers via topological sort.
        foreach ($this->topologicalSort($module->getFunctions()) as $fn) {
            $this->analyzeFunction($fn);
        }
    }

    /**
     * Topological sort: callees first so their summaries are ready.
     *
     * @param list<Function_> $functions
     * @return list<Function_>
     */
    private function topologicalSort(array $functions): array
    {
        /** @var array<string, Function_> */
        $byName = [];
        foreach ($functions as $fn) {
            $byName[$fn->getName()] = $fn;
        }

        /** @var array<string, list<string>> */
        $callees = [];
        foreach ($functions as $fn) {
            $callees[$fn->getName()] = [];
            foreach ($fn->getAllInstructions() as $inst) {
                if ($inst instanceof \PicoIR\Instruction\CallInstruction
                    && !in_array($inst->functionName, ['picohp_object_alloc', 'pico_array_new'], true)
                    && isset($byName[$inst->functionName])
                ) {
                    $callees[$fn->getName()][] = $inst->functionName;
                }
            }
        }

        /** @var array<string, true> */
        $visited = [];
        /** @var array<string, true> */
        $visiting = [];
        /** @var list<Function_> */
        $result = [];

        $visit = function (string $name) use (&$visit, &$visited, &$visiting, &$result, $byName, $callees): void {
            if (isset($visited[$name]) || !isset($byName[$name])) {
                return;
            }
            if (isset($visiting[$name])) {
                return; // cycle (SCC) — skip for now
            }
            $visiting[$name] = true;
            foreach ($callees[$name] as $callee) {
                $visit($callee);
            }
            unset($visiting[$name]);
            $visited[$name] = true;
            $result[] = $byName[$name];
        };

        foreach ($functions as $fn) {
            $visit($fn->getName());
        }

        return $result;
    }

    private function analyzeFunction(Function_ $fn): void
    {
        $summary = new FunctionSummary();
        $tracker = new ValueTracker();

        // ── Phase 1: Build value tracker ───────────────────────────

        $paramCount = 0;
        foreach ($fn->getAllInstructions() as $inst) {
            // Discover params via stores of Param values to alloca slots
            if ($inst instanceof StoreInstruction
                && $inst->value instanceof \PicoIR\Param
                && $inst->pointer instanceof AllocaInstruction) {
                $tracker->slotToParam[$inst->pointer->renderRef()] = $inst->value->index;
                $paramCount = max($paramCount, $inst->value->index + 1);
            }

            // Discover allocation sites
            if ($inst instanceof CallInstruction
                && in_array($inst->functionName, ['picohp_object_alloc', 'pico_array_new'], true)) {
                $ref = $inst->renderRef();
                $tracker->registerAlloc($ref);
                $summary->allocSites[$ref] = EscapeState::NoEscape;
            }

            // Track alloc-to-slot mappings
            if ($inst instanceof StoreInstruction
                && $inst->pointer instanceof AllocaInstruction) {
                $allocRef = $tracker->resolveToAlloc($inst->value);
                if ($allocRef !== null) {
                    $tracker->slotToAlloc[$inst->pointer->renderRef()] = $allocRef;
                }
            }
        }

        $summary->paramCount = $paramCount;
        for ($i = 0; $i < $paramCount; $i++) {
            $summary->paramStates[$i] = EscapeState::NoEscape;
        }

        // ── Phase 2: Analyze field stores ──────────────────────────

        foreach ($fn->getAllInstructions() as $inst) {
            if (!($inst instanceof StoreInstruction) || !($inst->pointer instanceof GepInstruction)) {
                continue;
            }
            $stored = $inst->value;
            $basePtrVal = $inst->pointer->basePtr;

            // Skip constant/null stores (type_id init, null field init)
            if ($stored instanceof \PicoIR\Constant || $stored instanceof \PicoIR\NullConstant) {
                continue;
            }

            $storedParamIdx = $tracker->resolveToParam($stored);
            $baseParamIdx = $tracker->resolveToParam($basePtrVal);

            // param X stored into param Y's field → X flows into Y
            if ($storedParamIdx !== null && $baseParamIdx !== null) {
                $summary->paramFlowsToParam[$storedParamIdx] ??= [];
                $summary->paramFlowsToParam[$storedParamIdx][] = $baseParamIdx;
                $summary->paramStates[$baseParamIdx] = $summary->paramStates[$baseParamIdx]->join(EscapeState::ArgEscape);
            }

            // alloc stored into param's field → alloc escapes via that param
            $storedAllocRef = $tracker->resolveToAlloc($stored);
            if ($storedAllocRef !== null && $baseParamIdx !== null) {
                $summary->allocSites[$storedAllocRef] = $summary->allocSites[$storedAllocRef]->join(EscapeState::ArgEscape);
            }
        }

        // ── Phase 3: Check returns ─────────────────────────────────

        foreach ($fn->getAllInstructions() as $inst) {
            if (!($inst instanceof ReturnInstruction) || $inst->value === null) {
                continue;
            }
            $allocRef = $tracker->resolveToAlloc($inst->value);
            if ($allocRef !== null) {
                $summary->allocSites[$allocRef] = EscapeState::GlobalEscape;
            }
            $paramIdx = $tracker->resolveToParam($inst->value);
            if ($paramIdx !== null) {
                $summary->paramFlowsToReturn[] = $paramIdx;
                $summary->paramStates[$paramIdx] = $summary->paramStates[$paramIdx]->join(EscapeState::GlobalEscape);
            }
        }

        // ── Phase 4: Apply callee summaries at call sites ──────────

        foreach ($fn->getAllInstructions() as $inst) {
            if (!($inst instanceof CallInstruction)) {
                continue;
            }
            if (in_array($inst->functionName, ['picohp_object_alloc', 'pico_array_new'], true)) {
                continue;
            }

            $calleeSummary = $this->summaries[$inst->functionName] ?? null;

            foreach ($inst->args as $argIdx => $argVal) {
                $allocRef = $tracker->resolveToAlloc($argVal);
                if ($allocRef === null) {
                    continue;
                }

                // Unknown callee → conservative
                if ($calleeSummary === null) {
                    $summary->allocSites[$allocRef] = EscapeState::GlobalEscape;
                    continue;
                }

                // Apply callee summary: check what happens to this param in the callee
                $paramState = $calleeSummary->paramStates[$argIdx] ?? EscapeState::NoEscape;

                // If the param flows to return in the callee, it escapes globally
                if (in_array($argIdx, $calleeSummary->paramFlowsToReturn, true)) {
                    $summary->allocSites[$allocRef] = EscapeState::GlobalEscape;
                    continue;
                }

                // If param X flows into param Y in the callee, check whether
                // the actual arg at Y escapes in *this* function.
                // Key insight: "X flows into Y" means "X escapes iff Y escapes at caller level"
                if (isset($calleeSummary->paramFlowsToParam[$argIdx])) {
                    foreach ($calleeSummary->paramFlowsToParam[$argIdx] as $targetParamIdx) {
                        if ($targetParamIdx < count($inst->args)) {
                            $targetArg = $inst->args[$targetParamIdx];
                            $targetAllocRef = $tracker->resolveToAlloc($targetArg);
                            if ($targetAllocRef !== null) {
                                // The target is also a local alloc — X escapes as far as that alloc does
                                $targetState = $summary->allocSites[$targetAllocRef];
                                $summary->allocSites[$allocRef] = $summary->allocSites[$allocRef]->join($targetState);
                            } else {
                                // Target is not a local alloc (e.g. a param from further up)
                                // Conservative: the alloc escapes via the callee
                                $summary->allocSites[$allocRef] = $summary->allocSites[$allocRef]->join(EscapeState::ArgEscape);
                            }
                        }
                    }
                    continue;
                }

                // No flows for this param — check its direct state.
                // ArgEscape means "callee stores things INTO this param's fields"
                // — the param itself doesn't leave the callee, so our alloc is safe.
                // Only GlobalEscape (returned, stored to global) makes the alloc escape.
                if ($paramState === EscapeState::GlobalEscape) {
                    $summary->allocSites[$allocRef] = EscapeState::GlobalEscape;
                }
            }
        }

        $this->summaries[$fn->getName()] = $summary;
    }

    /** @return array<string, FunctionSummary> */
    public function getSummaries(): array
    {
        return $this->summaries;
    }

    public function printResults(): void
    {
        foreach ($this->summaries as $fnName => $summary) {
            echo "  @{$fnName}:\n";

            if ($summary->paramStates !== []) {
                foreach ($summary->paramStates as $idx => $state) {
                    $extra = '';
                    if (isset($summary->paramFlowsToParam[$idx])) {
                        $extra .= ' (flows into param ' . implode(',', $summary->paramFlowsToParam[$idx]) . ')';
                    }
                    if (in_array($idx, $summary->paramFlowsToReturn, true)) {
                        $extra .= ' (returned)';
                    }
                    echo "    param[{$idx}]: {$state->name}{$extra}\n";
                }
            }

            if ($summary->allocSites === []) {
                echo "    (no allocations)\n";
            }
            foreach ($summary->allocSites as $id => $state) {
                $decision = match ($state) {
                    EscapeState::NoEscape => "\033[32m→ STACK ✓\033[0m",
                    EscapeState::ArgEscape => "\033[33m→ depends on caller\033[0m",
                    EscapeState::GlobalEscape => "\033[31m→ HEAP ✗\033[0m",
                };
                echo "    alloc {$id}: {$state->name} {$decision}\n";
            }
            echo "\n";
        }
    }
}
