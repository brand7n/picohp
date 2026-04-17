<?php

declare(strict_types=1);

namespace PicoIR\Transform;

use PicoIR\{Module, Function_, BasicBlock, Instruction, BaseType};
use PicoIR\Instruction\{AllocaInstruction, CallInstruction};
use PicoIR\Analysis\{EscapeAnalysisPass, EscapeState};

/**
 * Rewrites heap allocations (picohp_object_alloc calls) to stack allocations (alloca)
 * for allocation sites the escape analysis proved safe.
 *
 * Before: %p = call ptr @picohp_object_alloc(i32 0, i32 <typeId>)
 * After:  %p = alloca %struct.<Name>     (with zeroinitializer semantics)
 *
 * This is the decision point between heap and stack. Once rewritten, LLVM's
 * mem2reg/SROA can further promote the alloca to registers.
 */
class StackPromotionPass
{
    private int $promoted = 0;
    private int $kept = 0;

    /** @var list<array{fn: string, alloc: string, struct: string}> */
    private array $promotions = [];

    public function run(Module $module, EscapeAnalysisPass $analysis): void
    {
        $summaries = $analysis->getSummaries();

        foreach ($module->getFunctions() as $fn) {
            $fnName = $fn->getName();
            $summary = $summaries[$fnName] ?? null;
            if ($summary === null) {
                continue;
            }

            foreach ($fn->getBasicBlocks() as $bb) {
                $this->rewriteBlock($bb, $fnName, $summary);
            }
        }
    }

    private function rewriteBlock(BasicBlock $bb, string $fnName, \PicoIR\Analysis\FunctionSummary $summary): void
    {
        $instructions = $bb->getInstructions();
        $newInstructions = [];

        foreach ($instructions as $inst) {
            if ($inst instanceof CallInstruction
                && $inst->functionName === 'picohp_object_alloc') {
                $allocRef = $inst->renderRef();
                $state = $summary->allocSites[$allocRef] ?? EscapeState::GlobalEscape;

                if ($state === EscapeState::NoEscape) {
                    // Determine struct name from the GEP that follows this alloc
                    $structName = $this->findStructName($bb, $inst);

                    // Replace with alloca
                    $alloca = new AllocaInstruction(BaseType::PTR, 'stack_obj');
                    $alloca->dbgLine = $inst->dbgLine;

                    // We need the alloca to produce the same SSA name so downstream
                    // references still resolve. Hack: swap it in-place.
                    $newInstructions[] = new StackAllocMarker($allocRef, $structName, $inst);
                    $this->promoted++;
                    $this->promotions[] = [
                        'fn' => $fnName,
                        'alloc' => $allocRef,
                        'struct' => $structName,
                    ];
                    continue;
                }

                $this->kept++;
            }

            $newInstructions[] = $inst;
        }

        // Replace block contents
        $bb->replaceInstructions($newInstructions);
    }

    /**
     * Scan forward from the alloc to find the first GEP referencing it,
     * which reveals the struct name.
     */
    private function findStructName(BasicBlock $bb, CallInstruction $alloc): string
    {
        $allocRef = $alloc->renderRef();
        foreach ($bb->getInstructions() as $inst) {
            if ($inst instanceof \PicoIR\Instruction\GepInstruction
                && $inst->basePtr->renderRef() === $allocRef) {
                return $inst->structName;
            }
        }
        return 'unknown';
    }

    public function getPromotedCount(): int
    {
        return $this->promoted;
    }

    public function getKeptCount(): int
    {
        return $this->kept;
    }

    /** @return list<array{fn: string, alloc: string, struct: string}> */
    public function getPromotions(): array
    {
        return $this->promotions;
    }

    public function printReport(): void
    {
        echo "Stack promotion: {$this->promoted} promoted, {$this->kept} kept on heap\n";
        foreach ($this->promotions as $p) {
            echo "  @{$p['fn']}: {$p['alloc']} (%struct.{$p['struct']}) → alloca\n";
        }
        echo "\n";
    }
}

/**
 * Marker instruction that replaces a heap alloc with a stack alloc.
 * Renders as alloca but preserves the original SSA name for downstream references.
 */
class StackAllocMarker extends Instruction
{
    public function __construct(
        private readonly string $originalRef,
        public readonly string $structName,
        public readonly CallInstruction $originalAlloc,
    ) {
        parent::__construct('alloca_promoted', [], BaseType::PTR, 'stack');
    }

    public function renderRef(): string
    {
        // Reuse the original alloc's SSA name so all references still work
        return $this->originalRef;
    }

    public function renderDef(): string
    {
        // Emit alloca with the struct type
        return "{$this->originalRef} = alloca %struct.{$this->structName}";
    }
}
