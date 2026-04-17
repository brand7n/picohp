<?php

declare(strict_types=1);

/**
 * Full pipeline harness demonstrating:
 *
 *   1. Build IR from structured instructions
 *   2. Run escape analysis (interprocedural summaries)
 *   3. Interpret the IR directly (reference execution)
 *   4. Run stack promotion pass (rewrite allocs to alloca)
 *   5. Re-render to LLVM IR text (showing alloca vs malloc)
 *   6. Re-interpret after promotion (differential test: same result)
 *
 * Programs compute observable values so the interpreter can verify correctness.
 */

require_once __DIR__ . '/autoload.php';

use PicoIR\{Module, Builder, BaseType, Instruction, Constant, NullConstant, Param, Label, HeapObject};
use PicoIR\Instruction\CallInstruction;
use PicoIR\Analysis\EscapeAnalysisPass;
use PicoIR\Transform\StackPromotionPass;
use PicoIR\Interpreter;

function separator(string $title): void
{
    echo "\n\033[1m" . str_repeat('═', 70) . "\033[0m\n";
    echo "\033[1m  {$title}\033[0m\n";
    echo "\033[1m" . str_repeat('═', 70) . "\033[0m\n\n";
}

function emitNew(Builder $builder, string $structName, int $typeId): \PicoIR\ValueAbstract
{
    $objPtr = $builder->createObjectAlloc($structName, $typeId);
    $tidPtr = $builder->createStructGEP($structName, $objPtr, 0, BaseType::INT);
    $builder->createStore(new Constant($typeId, BaseType::INT), $tidPtr);
    return $objPtr;
}

// ═══════════════════════════════════════════════════════════════════
// Program: Point class with getX/getY, computes sum of coordinates
//
//   class Point { int $tid; int $x; int $y; }
//   Point::__construct($this, x, y) { $this->x = x; $this->y = y; }
//   Point::getX($this) { return $this->x; }
//   Point::getY($this) { return $this->y; }
//   function sumCoords(): int {
//       $p = new Point(10, 20);
//       return Point::getX($p) + Point::getY($p);
//   }
//   function main(): int { return sumCoords(); }
//
//   Expected result: 30
//   Expected escape: Point alloc in sumCoords → STACK
// ═══════════════════════════════════════════════════════════════════

function buildPointProgram(): Module
{
    Instruction::resetCounter();
    $module = new Module('point_program');
    $builder = new Builder($module);

    $module->addHeaderLine('; Point: { i32 type_id, i32 x, i32 y }');
    $module->addHeaderLine('%struct.Point = type { i32, i32, i32 }');
    $module->addHeaderLine('declare ptr @picohp_object_alloc(i64, i32)');

    // ── Point::__construct($this, $x, $y) ──
    $ctor = $module->addFunction('Point___construct', BaseType::VOID, [BaseType::PTR, BaseType::INT, BaseType::INT]);
    $entry = $ctor->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $thisSlot = $builder->createAlloca(BaseType::PTR, 'this');
    $xSlot = $builder->createAlloca(BaseType::INT, 'x');
    $ySlot = $builder->createAlloca(BaseType::INT, 'y');
    $builder->createStore(new Param(0, BaseType::PTR), $thisSlot);
    $builder->createStore(new Param(1, BaseType::INT), $xSlot);
    $builder->createStore(new Param(2, BaseType::INT), $ySlot);
    $thisVal = $builder->createLoad($thisSlot);
    $xVal = $builder->createLoad($xSlot);
    $yVal = $builder->createLoad($ySlot);
    $xPtr = $builder->createStructGEP('Point', $thisVal, 1, BaseType::INT);
    $builder->createStore($xVal, $xPtr);
    $yPtr = $builder->createStructGEP('Point', $thisVal, 2, BaseType::INT);
    $builder->createStore($yVal, $yPtr);
    $builder->createRetVoid();

    // ── Point::getX($this) → int ──
    $getX = $module->addFunction('Point__getX', BaseType::INT, [BaseType::PTR]);
    $entry = $getX->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $thisSlot = $builder->createAlloca(BaseType::PTR, 'this');
    $builder->createStore(new Param(0, BaseType::PTR), $thisSlot);
    $thisVal = $builder->createLoad($thisSlot);
    $xPtr = $builder->createStructGEP('Point', $thisVal, 1, BaseType::INT);
    $xVal = $builder->createLoad($xPtr);
    $builder->createRet($xVal);

    // ── Point::getY($this) → int ──
    $getY = $module->addFunction('Point__getY', BaseType::INT, [BaseType::PTR]);
    $entry = $getY->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $thisSlot = $builder->createAlloca(BaseType::PTR, 'this');
    $builder->createStore(new Param(0, BaseType::PTR), $thisSlot);
    $thisVal = $builder->createLoad($thisSlot);
    $yPtr = $builder->createStructGEP('Point', $thisVal, 2, BaseType::INT);
    $yVal = $builder->createLoad($yPtr);
    $builder->createRet($yVal);

    // ── sumCoords() → int ──
    $sum = $module->addFunction('sumCoords', BaseType::INT);
    $entry = $sum->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $pLocal = $builder->createAlloca(BaseType::PTR, 'p');
    $pObj = emitNew($builder, 'Point', 1);
    // Call constructor
    $builder->createCall('Point___construct', [$pObj, new Constant(10, BaseType::INT), new Constant(20, BaseType::INT)], BaseType::VOID);
    $builder->createStore($pObj, $pLocal);
    $pVal = $builder->createLoad($pLocal);
    $x = $builder->createCall('Point__getX', [$pVal], BaseType::INT);
    $pVal2 = $builder->createLoad($pLocal);
    $y = $builder->createCall('Point__getY', [$pVal2], BaseType::INT);
    $result = $builder->createAdd($x, $y);
    $builder->createRet($result);

    // ── main() → int ──
    $main = $module->addFunction('main', BaseType::INT);
    $entry = $main->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $result = $builder->createCall('sumCoords', [], BaseType::INT);
    $builder->createRet($result);

    return $module;
}

// ═══════════════════════════════════════════════════════════════════
// Program: Foo/Bar setFoo (the motivating example from our discussion)
// ═══════════════════════════════════════════════════════════════════

function buildSetFooProgram(): Module
{
    Instruction::resetCounter();
    $module = new Module('setfoo_program');
    $builder = new Builder($module);

    $module->addHeaderLine('%struct.Foo = type { i32 }');
    $module->addHeaderLine('%struct.Bar = type { i32, ptr }');
    $module->addHeaderLine('declare ptr @picohp_object_alloc(i64, i32)');

    // Bar::setFoo
    $setFoo = $module->addFunction('Bar__setFoo', BaseType::VOID, [BaseType::PTR, BaseType::PTR]);
    $entry = $setFoo->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $ts = $builder->createAlloca(BaseType::PTR, 'this');
    $fs = $builder->createAlloca(BaseType::PTR, 'foo_p');
    $builder->createStore(new Param(0, BaseType::PTR), $ts);
    $builder->createStore(new Param(1, BaseType::PTR), $fs);
    $tv = $builder->createLoad($ts);
    $fv = $builder->createLoad($fs);
    $fp = $builder->createStructGEP('Bar', $tv, 1, BaseType::PTR);
    $builder->createStore($fv, $fp);
    $builder->createRetVoid();

    // Bar::getFoo → ptr
    $getFoo = $module->addFunction('Bar__getFoo', BaseType::PTR, [BaseType::PTR]);
    $entry = $getFoo->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $ts = $builder->createAlloca(BaseType::PTR, 'this');
    $builder->createStore(new Param(0, BaseType::PTR), $ts);
    $tv = $builder->createLoad($ts);
    $fp = $builder->createStructGEP('Bar', $tv, 1, BaseType::PTR);
    $fv = $builder->createLoad($fp);
    $builder->createRet($fv);

    // Foo::getTypeId → int
    $getTid = $module->addFunction('Foo__getTypeId', BaseType::INT, [BaseType::PTR]);
    $entry = $getTid->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $ts = $builder->createAlloca(BaseType::PTR, 'this');
    $builder->createStore(new Param(0, BaseType::PTR), $ts);
    $tv = $builder->createLoad($ts);
    $tp = $builder->createStructGEP('Foo', $tv, 0, BaseType::INT);
    $tid = $builder->createLoad($tp);
    $builder->createRet($tid);

    // example() → int: create Foo+Bar, wire them, read back foo's type_id
    $example = $module->addFunction('example', BaseType::INT);
    $entry = $example->addBasicBlock('entry');
    $builder->setInsertPoint($entry);

    $fooLocal = $builder->createAlloca(BaseType::PTR, 'foo');
    $barLocal = $builder->createAlloca(BaseType::PTR, 'bar');

    $fooObj = emitNew($builder, 'Foo', 42);
    $builder->createStore($fooObj, $fooLocal);

    $barObj = emitNew($builder, 'Bar', 99);
    $barFooField = $builder->createStructGEP('Bar', $barObj, 1, BaseType::PTR);
    $builder->createStore(new NullConstant(), $barFooField);
    $builder->createStore($barObj, $barLocal);

    // $bar->setFoo($foo)
    $barVal = $builder->createLoad($barLocal);
    $fooArg = $builder->createLoad($fooLocal);
    $builder->createCall('Bar__setFoo', [$barVal, $fooArg], BaseType::VOID);

    // $retrieved = $bar->getFoo()
    $barVal2 = $builder->createLoad($barLocal);
    $retrieved = $builder->createCall('Bar__getFoo', [$barVal2], BaseType::PTR);

    // return $retrieved->getTypeId()  (should be 42)
    $tid = $builder->createCall('Foo__getTypeId', [$retrieved], BaseType::INT);
    $builder->createRet($tid);

    // main
    $main = $module->addFunction('main', BaseType::INT);
    $entry = $main->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $result = $builder->createCall('example', [], BaseType::INT);
    $builder->createRet($result);

    return $module;
}

// ═══════════════════════════════════════════════════════════════════
// Full pipeline runner
// ═══════════════════════════════════════════════════════════════════

function runPipeline(string $name, Module $module, int $expectedResult, array $expectedDecisions): bool
{
    separator($name);

    $ok = true;

    // ── Step 1: Render original IR ─────────────────────────────
    echo "── Original LLVM IR ──\n\n";
    echo $module->dump();

    // ── Step 2: Interpret (before promotion) ───────────────────
    echo "── Interpreter (before promotion) ──\n\n";
    $interp = new Interpreter();
    $result = $interp->run($module, 'main');
    echo "  main() returned: {$result}\n";
    if ($result !== $expectedResult) {
        echo "  \033[31m✗ Expected {$expectedResult}\033[0m\n";
        $ok = false;
    } else {
        echo "  \033[32m✓ Correct\033[0m\n";
    }
    echo "\n";

    // ── Step 3: Escape analysis ────────────────────────────────
    echo "── Escape Analysis ──\n\n";
    $analysis = new EscapeAnalysisPass();
    $analysis->run($module);
    $analysis->printResults();

    // Verify expected decisions
    $allDecisions = [];
    foreach ($analysis->getSummaries() as $summary) {
        foreach ($summary->allocSites as $ref => $state) {
            $allDecisions[] = match ($state) {
                \PicoIR\Analysis\EscapeState::NoEscape => 'STACK',
                \PicoIR\Analysis\EscapeState::ArgEscape => 'ARG',
                \PicoIR\Analysis\EscapeState::GlobalEscape => 'HEAP',
            };
        }
    }
    if ($allDecisions === $expectedDecisions) {
        echo "  \033[32m✓ Escape decisions correct: " . implode(', ', $allDecisions) . "\033[0m\n\n";
    } else {
        echo "  \033[31m✗ Escape decisions wrong\033[0m\n";
        echo "    expected: " . implode(', ', $expectedDecisions) . "\n";
        echo "    got:      " . implode(', ', $allDecisions) . "\n\n";
        $ok = false;
    }

    // ── Step 4: Stack promotion ────────────────────────────────
    echo "── Stack Promotion ──\n\n";
    $promotion = new StackPromotionPass();
    $promotion->run($module, $analysis);
    $promotion->printReport();

    // ── Step 5: Render promoted IR ─────────────────────────────
    echo "── Promoted LLVM IR ──\n\n";
    echo $module->dump();

    // ── Step 6: Interpret (after promotion) ─────────────────────
    echo "── Interpreter (after promotion — differential test) ──\n\n";
    $interp2 = new Interpreter();
    $result2 = $interp2->run($module, 'main');
    echo "  main() returned: {$result2}\n";
    if ($result2 !== $expectedResult) {
        echo "  \033[31m✗ Differential test FAILED: expected {$expectedResult}, got {$result2}\033[0m\n";
        $ok = false;
    } else {
        echo "  \033[32m✓ Differential test passed (same result before & after promotion)\033[0m\n";
    }
    echo "\n";

    return $ok;
}

// ═══════════════════════════════════════════════════════════════════
// Run both programs
// ═══════════════════════════════════════════════════════════════════

$passed = 0;
$total = 0;

$total++;
if (runPipeline(
    'Point program: new Point(10, 20) → getX + getY = 30',
    buildPointProgram(),
    expectedResult: 30,
    expectedDecisions: ['STACK'],
)) {
    $passed++;
}

$total++;
if (runPipeline(
    'Foo/Bar setFoo: new Foo(42), new Bar(99), bar.setFoo(foo), bar.getFoo().typeId = 42',
    buildSetFooProgram(),
    expectedResult: 42,
    expectedDecisions: ['STACK', 'STACK'],
)) {
    $passed++;
}

separator("FINAL: {$passed}/{$total} programs passed full pipeline");

// ═══════════════════════════════════════════════════════════════════
// ARM64 assembly output (stack-machine style)
// ═══════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════
// ARM64: stack-machine vs register-allocated (Point___construct)
// ═══════════════════════════════════════════════════════════════════

separator("ARM64 — Stack Machine (no regalloc)");

$pointStack = buildPointProgram();
$sa1 = new EscapeAnalysisPass(); $sa1->run($pointStack);
$sp1 = new StackPromotionPass(); $sp1->run($pointStack, $sa1);

$stackEmitter = new \PicoIR\Codegen\Arm64Emitter();
$stackAsm = $stackEmitter->emit($pointStack);
$stackLines = explode("\n", $stackAsm);
// Show just Point___construct for comparison
$inFn = false;
$stackCount = 0;
foreach ($stackLines as $line) {
    if (str_contains($line, '_Point___construct:')) { $inFn = true; }
    if ($inFn) {
        echo $line . "\n";
        if (str_starts_with(trim($line), 'ret') && !str_contains($line, 'return')) {
            break;
        }
        if (str_starts_with($line, '    ') && !str_starts_with($line, '    ;')) {
            $stackCount++;
        }
    }
}
echo "\n  instructions: {$stackCount}\n";

separator("ARM64 — Linear Scan Register Allocation");

$pointReg = buildPointProgram();
$sa2 = new EscapeAnalysisPass(); $sa2->run($pointReg);
$sp2 = new StackPromotionPass(); $sp2->run($pointReg, $sa2);

$regEmitter = new \PicoIR\Codegen\Arm64Emitter();
$regEmitter->enableRegAlloc();
$regAsm = $regEmitter->emit($pointReg);
$regLines = explode("\n", $regAsm);
$inFn = false;
$regCount = 0;
foreach ($regLines as $line) {
    if (str_contains($line, '_Point___construct:')) { $inFn = true; }
    if ($inFn) {
        echo $line . "\n";
        if (str_starts_with(trim($line), 'ret') && !str_contains($line, 'return')) {
            break;
        }
        if (str_starts_with($line, '    ') && !str_starts_with($line, '    ;')) {
            $regCount++;
        }
    }
}
echo "\n  instructions: {$regCount}  (saved " . ($stackCount - $regCount) . ")\n";

// Full program with regalloc for testing
separator("Full ARM64 with regalloc (for testing)");
echo $regAsm;
echo "\n; To build: clang -o point point.s\n";
echo "; ./point; echo \$?   # expected: 30\n";

exit($passed === $total ? 0 : 1);
