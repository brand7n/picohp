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
use PicoIR\Transform\{StackPromotionPass, StoreToLoadForwardingPass, DeadInstructionEliminationPass};
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
// Rect program: area/perimeter/diag — DCE eliminates dead computations
// ═══════════════════════════════════════════════════════════════════

function buildRectProgram(): Module
{
    Instruction::resetCounter();
    $module = new Module('rect_program');
    $builder = new Builder($module);

    $module->addHeaderLine('%struct.Rect = type { i32, i32, i32 }');
    $module->addHeaderLine('declare ptr @picohp_object_alloc(i64, i32)');

    // Rect::__construct($this, $w, $h)
    $ctor = $module->addFunction('Rect___construct', BaseType::VOID, [BaseType::PTR, BaseType::INT, BaseType::INT]);
    $entry = $ctor->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $thisSlot = $builder->createAlloca(BaseType::PTR, 'this');
    $wSlot = $builder->createAlloca(BaseType::INT, 'w');
    $hSlot = $builder->createAlloca(BaseType::INT, 'h');
    $builder->createStore(new Param(0, BaseType::PTR), $thisSlot);
    $builder->createStore(new Param(1, BaseType::INT), $wSlot);
    $builder->createStore(new Param(2, BaseType::INT), $hSlot);
    $thisVal = $builder->createLoad($thisSlot);
    $wVal = $builder->createLoad($wSlot);
    $hVal = $builder->createLoad($hSlot);
    $wPtr = $builder->createStructGEP('Rect', $thisVal, 1, BaseType::INT);
    $builder->createStore($wVal, $wPtr);
    $hPtr = $builder->createStructGEP('Rect', $thisVal, 2, BaseType::INT);
    $builder->createStore($hVal, $hPtr);
    $builder->createRetVoid();

    // Rect::getW($this) → int
    $getW = $module->addFunction('Rect__getW', BaseType::INT, [BaseType::PTR]);
    $entry = $getW->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $thisSlot = $builder->createAlloca(BaseType::PTR, 'this');
    $builder->createStore(new Param(0, BaseType::PTR), $thisSlot);
    $thisVal = $builder->createLoad($thisSlot);
    $wPtr = $builder->createStructGEP('Rect', $thisVal, 1, BaseType::INT);
    $wVal = $builder->createLoad($wPtr);
    $builder->createRet($wVal);

    // Rect::getH($this) → int
    $getH = $module->addFunction('Rect__getH', BaseType::INT, [BaseType::PTR]);
    $entry = $getH->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $thisSlot = $builder->createAlloca(BaseType::PTR, 'this');
    $builder->createStore(new Param(0, BaseType::PTR), $thisSlot);
    $thisVal = $builder->createLoad($thisSlot);
    $hPtr = $builder->createStructGEP('Rect', $thisVal, 2, BaseType::INT);
    $hVal = $builder->createLoad($hPtr);
    $builder->createRet($hVal);

    // compute() → int: area=w*h (live), perim=(w+h)*2 (dead), diagSq=w²+h² (dead)
    $compute = $module->addFunction('compute', BaseType::INT);
    $entry = $compute->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $rLocal = $builder->createAlloca(BaseType::PTR, 'r');
    $rObj = emitNew($builder, 'Rect', 1);
    $builder->createCall('Rect___construct', [$rObj, new Constant(5, BaseType::INT), new Constant(3, BaseType::INT)], BaseType::VOID);
    $builder->createStore($rObj, $rLocal);
    $rVal1 = $builder->createLoad($rLocal);
    $w = $builder->createCall('Rect__getW', [$rVal1], BaseType::INT);
    $rVal2 = $builder->createLoad($rLocal);
    $h = $builder->createCall('Rect__getH', [$rVal2], BaseType::INT);
    $area = $builder->createBinOp('mul', $w, $h);
    $wPlusH = $builder->createAdd($w, $h);
    $perim = $builder->createBinOp('mul', $wPlusH, new Constant(2, BaseType::INT));
    $wSq = $builder->createBinOp('mul', $w, $w);
    $hSq = $builder->createBinOp('mul', $h, $h);
    $diagSq = $builder->createAdd($wSq, $hSq);
    $builder->createRet($area);

    // main
    $main = $module->addFunction('main', BaseType::INT);
    $entry = $main->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $result = $builder->createCall('compute', [], BaseType::INT);
    $builder->createRet($result);

    return $module;
}

// ═══════════════════════════════════════════════════════════════════
// Full optimization pipeline → ARM64 for both programs
// ═══════════════════════════════════════════════════════════════════

/**
 * Run: escape analysis → stack promotion → store-to-load forwarding → DCE
 */
function optimizeModule(Module $module): void
{
    $ea = new EscapeAnalysisPass();
    $ea->run($module);
    $sp = new StackPromotionPass();
    $sp->run($module, $ea);
    $fwd = new StoreToLoadForwardingPass();
    $fwd->run($module);
    $dce = new DeadInstructionEliminationPass();
    $dce->run($module);
}

function countInstructions(\PicoIR\Function_ $fn): int
{
    $count = 0;
    foreach ($fn->getAllInstructions() as $_) {
        $count++;
    }
    return $count;
}

/**
 * Run the full pipeline on a program, emit ARM64, verify via interpreter + native execution.
 */
function arm64Pipeline(string $name, callable $buildFn, int $expected): void
{
    // Baseline
    $baselineModule = $buildFn();
    $baseline = (new Interpreter())->run($baselineModule, 'main');
    assert($baseline === $expected, "Baseline mismatch: {$baseline} !== {$expected}");

    // Count before
    $beforeCounts = [];
    $beforeTotal = 0;
    foreach ($baselineModule->getFunctions() as $fn) {
        $c = countInstructions($fn);
        $beforeCounts[$fn->getName()] = $c;
        $beforeTotal += $c;
    }

    // Optimize
    $module = $buildFn();
    optimizeModule($module);

    // Verify interpreter still correct
    $afterResult = (new Interpreter())->run($module, 'main');
    assert($afterResult === $expected, "Post-opt mismatch: {$afterResult} !== {$expected}");

    // Count after
    $afterTotal = 0;
    foreach ($module->getFunctions() as $fn) {
        $afterTotal += countInstructions($fn);
    }

    separator("ARM64: {$name}  (IR: {$beforeTotal} → {$afterTotal} instructions)");

    // Show optimized IR
    echo "── Optimized IR ──\n\n";
    echo $module->dump();

    // Emit ARM64 with regalloc
    echo "── ARM64 (regalloc) ──\n\n";
    $emitter = new \PicoIR\Codegen\Arm64Emitter();
    $emitter->enableRegAlloc();
    $asm = $emitter->emit($module);
    echo $asm;

    echo "\n  IR: {$beforeTotal} → {$afterTotal} instructions";
    echo " (" . ($beforeTotal - $afterTotal) . " removed, " . round(100 * ($beforeTotal - $afterTotal) / $beforeTotal) . "% reduction)\n";
    echo "  interpreter: {$afterResult} ✓\n";
}

arm64Pipeline('Point (10+20=30)', 'buildPointProgram', 30);
arm64Pipeline('Rect (5×3=15, dead perim+diag eliminated)', 'buildRectProgram', 15);

// Write the Rect program to a file and test natively
separator("Native execution test — Rect program");

$rectModule = buildRectProgram();
optimizeModule($rectModule);
$emitter = new \PicoIR\Codegen\Arm64Emitter();
$emitter->enableRegAlloc();
$rectAsm = $emitter->emit($rectModule);
$tmpFile = sys_get_temp_dir() . '/picoIR_rect.s';
$tmpBin = sys_get_temp_dir() . '/picoIR_rect';
file_put_contents($tmpFile, $rectAsm);
$buildOut = shell_exec("clang -o {$tmpBin} {$tmpFile} 2>&1");
if ($buildOut !== null && $buildOut !== '') {
    echo "Build output: {$buildOut}\n";
}
$exitCode = -1;
exec($tmpBin, $output, $exitCode);
echo "  {$tmpBin} → exit code {$exitCode}";
if ($exitCode === 15) {
    echo "  \033[32m✓\033[0m\n";
} else {
    echo "  \033[31m✗ expected 15\033[0m\n";
}

// Also test Point natively
$pointModule = buildPointProgram();
optimizeModule($pointModule);
$emitter2 = new \PicoIR\Codegen\Arm64Emitter();
$emitter2->enableRegAlloc();
$pointAsm = $emitter2->emit($pointModule);
$tmpFile2 = sys_get_temp_dir() . '/picoIR_point.s';
$tmpBin2 = sys_get_temp_dir() . '/picoIR_point';
file_put_contents($tmpFile2, $pointAsm);
shell_exec("clang -o {$tmpBin2} {$tmpFile2} 2>&1");
$exitCode2 = -1;
exec($tmpBin2, $output2, $exitCode2);
echo "  {$tmpBin2} → exit code {$exitCode2}";
if ($exitCode2 === 30) {
    echo "  \033[32m✓\033[0m\n";
} else {
    echo "  \033[31m✗ expected 30\033[0m\n";
}

exit($passed === $total ? 0 : 1);
