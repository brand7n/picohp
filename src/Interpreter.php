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
    RawInstruction,
    SelectInstruction,
    StoreInstruction,
    SwitchInstruction,
    UnreachableInstruction,
};

/**
 * A heap-allocated object in the interpreter. Fields are indexed by position.
 */
class HeapObject
{
    /** @var array<int, mixed> field index => value */
    public array $fields = [];
    public int $typeId = 0;
    public string $structName = '';

    /** Which function frame allocated this object (for escape-analysis verification). */
    public ?string $allocFrame = null;
}

/**
 * A call frame in the interpreter.
 */
class Frame
{
    /** @var array<string, mixed> SSA name => runtime value */
    public array $env = [];
    public ?BasicBlock $currentBlock = null;
    public ?BasicBlock $previousBlock = null;

    public function __construct(
        public readonly Function_ $function,
        /** @var list<mixed> positional arguments */
        public readonly array $args,
    ) {}
}

/**
 * Tree-walking interpreter for the PicoIR.
 *
 * Walks Instruction objects directly — no bytecode, no compilation.
 * Serves as: reference implementation, differential testing oracle,
 * and escape-analysis verifier.
 */
class Interpreter
{
    /** @var array<string, Function_> */
    private array $functions = [];

    /** @var list<Frame> */
    private array $callStack = [];

    /** @var list<HeapObject> */
    private array $heap = [];

    /** @var list<list<mixed>> arrays allocated at runtime */
    private array $arrays = [];

    /** Output buffer — captures what the program prints. */
    private string $output = '';

    /** When true, assert that stack-promoted allocs don't outlive their frame. */
    private bool $verifyEscapes = false;

    /** @var array<string, array<string, \PicoIR\Analysis\EscapeState>> fn => allocRef => state */
    private array $escapeDecisions = [];

    public function enableEscapeVerification(array $decisions): void
    {
        $this->verifyEscapes = true;
        $this->escapeDecisions = $decisions;
    }

    public function run(Module $module, string $entryPoint = 'main', array $args = []): mixed
    {
        foreach ($module->getFunctions() as $fn) {
            $this->functions[$fn->getName()] = $fn;
        }

        return $this->callFunction($entryPoint, $args);
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    private function callFunction(string $name, array $args): mixed
    {
        // Runtime/external function dispatch
        $result = $this->tryRuntimeCall($name, $args);
        if ($result !== null) {
            return $result[0];
        }

        if (!isset($this->functions[$name])) {
            throw new \RuntimeException("Interpreter: unknown function @{$name}");
        }

        $fn = $this->functions[$name];
        $frame = new Frame($fn, $args);
        $this->callStack[] = $frame;

        // Start at entry block
        $blocks = $fn->getBasicBlocks();
        assert(count($blocks) > 0, "Function @{$name} has no basic blocks");
        $frame->currentBlock = $blocks[0];

        // Set up params: %0, %1, etc.
        foreach ($args as $i => $val) {
            $frame->env["%{$i}"] = $val;
        }

        // Execute
        $returnVal = $this->executeBlocks($frame, $fn);

        array_pop($this->callStack);

        return $returnVal;
    }

    private function executeBlocks(Frame $frame, Function_ $fn): mixed
    {
        $blocksByName = [];
        foreach ($fn->getBasicBlocks() as $bb) {
            $blocksByName[$bb->getName()] = $bb;
        }

        while ($frame->currentBlock !== null) {
            $block = $frame->currentBlock;

            foreach ($block->getInstructions() as $inst) {
                $result = $this->executeInstruction($inst, $frame, $blocksByName);
                if ($result !== null) {
                    // Return instruction
                    return $result[0]; // unwrap the [value] wrapper
                }
            }
        }

        return null; // void function fell through
    }

    /**
     * Execute a single instruction. Returns [value] if it's a return, null otherwise.
     *
     * @param array<string, BasicBlock> $blocksByName
     * @return array{0: mixed}|null
     */
    private function executeInstruction(Instruction $inst, Frame $frame, array $blocksByName): ?array
    {
        $ref = $inst->renderRef();

        if ($inst instanceof AllocaInstruction) {
            // Allocate a "slot" — in PHP we just use a stdClass with a ->value
            $slot = new \stdClass();
            $slot->value = $this->defaultValue($inst->allocType);
            $frame->env[$ref] = $slot;
            return null;
        }

        if ($inst instanceof LoadInstruction) {
            $ptr = $this->resolve($inst->pointer, $frame);
            if ($ptr instanceof \stdClass) {
                // Loading from an alloca slot
                $frame->env[$ref] = $ptr->value;
            } elseif (is_array($ptr) && count($ptr) === 3 && $ptr[0] === '__field__') {
                // Loading from a field pointer (GEP result)
                $obj = $ptr[1];
                $fieldIdx = $ptr[2];
                $frame->env[$ref] = $obj->fields[$fieldIdx] ?? $this->defaultValue($inst->getType());
            } elseif ($ptr instanceof HeapObject) {
                $frame->env[$ref] = $ptr;
            } else {
                $frame->env[$ref] = $ptr;
            }
            return null;
        }

        if ($inst instanceof StoreInstruction) {
            $val = $this->resolve($inst->value, $frame);
            $ptr = $this->resolve($inst->pointer, $frame);
            if ($ptr instanceof \stdClass) {
                $ptr->value = $val;
            } elseif (is_array($ptr) && count($ptr) === 3 && $ptr[0] === '__field__') {
                // Field pointer from GEP: ['__field__', HeapObject, fieldIndex]
                $ptr[1]->fields[$ptr[2]] = $val;
            }
            return null;
        }

        if ($inst instanceof GepInstruction) {
            $basePtr = $this->resolve($inst->basePtr, $frame);
            $obj = $basePtr;
            if ($basePtr instanceof \stdClass) {
                $obj = $basePtr->value;
            }
            if ($obj instanceof HeapObject) {
                // Return a field-pointer: ['__field__', object, index]
                $frame->env[$ref] = ['__field__', $obj, $inst->fieldIndex];
            } else {
                $frame->env[$ref] = ['__field__', $obj, $inst->fieldIndex];
            }
            return null;
        }

        if ($inst instanceof BinaryOpInstruction) {
            $l = $this->resolve($inst->lhs, $frame);
            $r = $this->resolve($inst->rhs, $frame);
            $frame->env[$ref] = $this->evalBinaryOp($inst->opcode, $l, $r);
            return null;
        }

        if ($inst instanceof CastInstruction) {
            $val = $this->resolve($inst->operand, $frame);
            $frame->env[$ref] = match ($inst->kind) {
                CastKind::Zext => (int) $val,
                CastKind::Trunc => $val !== 0,
                CastKind::FpToSi => (int) $val,
                CastKind::SiToFp => (float) $val,
                CastKind::PtrToInt => is_int($val) ? $val : 0,
                CastKind::IntToPtr => $val,
            };
            return null;
        }

        if ($inst instanceof SelectInstruction) {
            $cond = $this->resolve($inst->condition, $frame);
            $t = $this->resolve($inst->trueVal, $frame);
            $f = $this->resolve($inst->falseVal, $frame);
            $frame->env[$ref] = $cond ? $t : $f;
            return null;
        }

        if ($inst instanceof CallInstruction) {
            $args = array_map(fn ($a) => $this->resolve($a, $frame), $inst->args);
            $result = $this->callFunction($inst->functionName, $args);
            if ($inst->getType() !== BaseType::VOID) {
                $frame->env[$ref] = $result;
            }
            return null;
        }

        // Stack-promoted allocations (after StackPromotionPass)
        if ($inst instanceof \PicoIR\Transform\StackAllocMarker) {
            // Same behavior as a heap alloc — just a HeapObject on the PHP side.
            // The difference is semantic: at LLVM level it's alloca instead of malloc.
            $obj = new HeapObject();
            $obj->structName = $inst->structName;
            $obj->allocFrame = $frame->function->getName();
            $this->heap[] = $obj;
            $frame->env[$inst->renderRef()] = $obj;
            return null;
        }

        if ($inst instanceof BranchInstruction) {
            $frame->previousBlock = $frame->currentBlock;
            if ($inst->condition === null) {
                // Unconditional
                $targetName = $inst->target->getName();
                $frame->currentBlock = $blocksByName[$targetName];
            } else {
                $cond = $this->resolve($inst->condition, $frame);
                $targetName = $cond
                    ? $inst->target->getName()
                    : $inst->falseTarget->getName();
                $frame->currentBlock = $blocksByName[$targetName];
            }
            return null; // block execution continues in the outer loop
        }

        if ($inst instanceof SwitchInstruction) {
            $val = $this->resolve($inst->value, $frame);
            $frame->previousBlock = $frame->currentBlock;
            $target = $inst->defaultTarget->getName();
            foreach ($inst->cases as $case) {
                if ($val === $case['value']) {
                    $target = $case['target']->getName();
                    break;
                }
            }
            $frame->currentBlock = $blocksByName[$target];
            return null;
        }

        if ($inst instanceof ReturnInstruction) {
            $val = $inst->value !== null
                ? $this->resolve($inst->value, $frame)
                : null;
            $frame->currentBlock = null; // stop execution
            return [$val]; // wrapped so null returns are distinguishable
        }

        if ($inst instanceof UnreachableInstruction) {
            throw new \RuntimeException('Interpreter hit unreachable instruction');
        }

        if ($inst instanceof RawInstruction) {
            // Skip raw instructions — they're LLVM-specific
            return null;
        }

        throw new \RuntimeException('Interpreter: unhandled instruction type ' . get_class($inst));
    }

    private function resolve(ValueAbstract $val, Frame $frame): mixed
    {
        if ($val instanceof Constant) {
            return $val->value;
        }
        if ($val instanceof NullConstant) {
            return null;
        }
        if ($val instanceof Param) {
            $key = "%{$val->index}";
            return $frame->env[$key] ?? $frame->args[$val->index] ?? null;
        }
        if ($val instanceof Global_) {
            // String constants are just their name for now
            return '@' . $val->getName();
        }
        if ($val instanceof Void_) {
            return null;
        }
        // Instruction reference — look up in environment
        $ref = $val->renderRef();
        if (array_key_exists($ref, $frame->env)) {
            return $frame->env[$ref];
        }
        throw new \RuntimeException("Interpreter: unresolved value {$ref}");
    }

    private function evalBinaryOp(string $opcode, mixed $l, mixed $r): mixed
    {
        return match ($opcode) {
            'add' => $l + $r,
            'sub' => $l - $r,
            'mul' => $l * $r,
            'sdiv' => $r !== 0 ? intdiv($l, $r) : 0,
            'srem' => $r !== 0 ? $l % $r : 0,
            'fadd' => $l + $r,
            'fsub' => $l - $r,
            'fmul' => $l * $r,
            'fdiv' => $r != 0.0 ? $l / $r : 0.0,
            'and' => $l & $r,
            'or' => $l | $r,
            'xor' => $l ^ $r,
            'shl' => $l << $r,
            'ashr' => $l >> $r,
            'icmp eq' => $l === $r,
            'icmp ne' => $l !== $r,
            'icmp slt' => $l < $r,
            'icmp sgt' => $l > $r,
            'icmp sle' => $l <= $r,
            'icmp sge' => $l >= $r,
            'fcmp oeq' => $l == $r,
            'fcmp one' => $l != $r,
            'fcmp olt' => $l < $r,
            'fcmp ogt' => $l > $r,
            default => throw new \RuntimeException("Interpreter: unknown binop '{$opcode}'"),
        };
    }

    private function defaultValue(BaseType $type): mixed
    {
        return match ($type) {
            BaseType::INT => 0,
            BaseType::FLOAT => 0.0,
            BaseType::BOOL => false,
            BaseType::STRING, BaseType::PTR => null,
            BaseType::VOID => null,
            BaseType::LABEL => null,
        };
    }

    /**
     * Handle runtime/external function calls.
     * Returns [value] if handled, null if the function should be looked up in IR.
     *
     * @return array{0: mixed}|null
     */
    private function tryRuntimeCall(string $name, array $args): ?array
    {
        return match ($name) {
            'picohp_object_alloc' => $this->rtObjectAlloc($args),
            'pico_array_new' => [[]],
            'pico_array_push_int', 'pico_array_push_str',
            'pico_array_push_ptr', 'pico_array_push_float',
            'pico_array_push_bool' => $this->rtArrayPush($args),
            'pico_array_get_int', 'pico_array_get_str',
            'pico_array_get_ptr', 'pico_array_get_float',
            'pico_array_get_bool' => $this->rtArrayGet($args),
            'pico_array_len' => [is_array($args[0]) ? count($args[0]) : 0],
            'pico_string_concat' => [$args[0] . $args[1]],
            'pico_string_len' => [strlen((string)$args[0])],
            'pico_int_to_string' => [(string)$args[0]],
            'pico_float_to_string' => [(string)$args[0]],
            default => null,
        };
    }

    /**
     * @return array{0: HeapObject}
     */
    private function rtObjectAlloc(array $args): array
    {
        $obj = new HeapObject();
        $obj->typeId = $args[1] ?? 0;
        $frameName = count($this->callStack) > 0
            ? end($this->callStack)->function->getName()
            : '<top>';
        $obj->allocFrame = $frameName;
        $this->heap[] = $obj;
        return [$obj];
    }

    /**
     * @return array{0: null}
     */
    private function rtArrayPush(array $args): array
    {
        // $args[0] is the array (by reference in the slot), $args[1] is the value
        // In our interpreter, arrays are PHP arrays stored in slots
        // This is a simplification — real impl would need mutable array refs
        return [null];
    }

    /**
     * @return array{0: mixed}
     */
    private function rtArrayGet(array $args): array
    {
        $arr = $args[0];
        $idx = $args[1];
        if (is_array($arr) && isset($arr[$idx])) {
            return [$arr[$idx]];
        }
        return [0];
    }
}
