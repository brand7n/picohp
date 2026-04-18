<?php

declare(strict_types=1);

/**
 * Test programs that exercise dead instruction elimination.
 *
 * After store-to-load forwarding cleans up alloca chains, these programs
 * have leftover instructions that DCE should catch:
 *
 *   1. Dead arithmetic — computed but never used
 *   2. Dead GEP + load — field loaded but result unused
 *   3. Cascading dead — removing one dead inst makes its operands dead too
 *   4. Mixed live/dead — some computations used, others not
 */

require_once __DIR__ . '/autoload.php';

use PicoIR\{Module, Builder, BaseType, Instruction, Constant, NullConstant, Param, Label};
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

function countInstructions(\PicoIR\Function_ $fn): int
{
    $count = 0;
    foreach ($fn->getAllInstructions() as $_) {
        $count++;
    }
    return $count;
}

// ═══════════════════════════════════════════════════════════════════
// Program: Rectangle with area + perimeter, but caller only uses area
//
//   class Rect { int tid; int w; int h; }
//   Rect::getW($this) → int
//   Rect::getH($this) → int
//   function compute(): int {
//       $r = new Rect(w=5, h=3);
//       $w = getW($r);
//       $h = getH($r);
//       $area = $w * $h;           // used — returned
//       $perim = ($w + $h) * 2;    // dead — never used
//       $diag = $w * $w + $h * $h; // dead — never used
//       return $area;
//   }
//
//   Expected: $perim and $diag computations eliminated by DCE
//   Result: 15
// ═══════════════════════════════════════════════════════════════════

function buildRectProgram(): Module
{
    Instruction::resetCounter();
    $module = new Module('rect_dce_test');
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

    // compute() → int
    $compute = $module->addFunction('compute', BaseType::INT);
    $entry = $compute->addBasicBlock('entry');
    $builder->setInsertPoint($entry);

    // $r = new Rect(5, 3)
    $rLocal = $builder->createAlloca(BaseType::PTR, 'r');
    $rObj = emitNew($builder, 'Rect', 1);
    $builder->createCall('Rect___construct', [$rObj, new Constant(5, BaseType::INT), new Constant(3, BaseType::INT)], BaseType::VOID);
    $builder->createStore($rObj, $rLocal);

    // $w = getW($r); $h = getH($r);
    $rVal1 = $builder->createLoad($rLocal);
    $w = $builder->createCall('Rect__getW', [$rVal1], BaseType::INT);
    $rVal2 = $builder->createLoad($rLocal);
    $h = $builder->createCall('Rect__getH', [$rVal2], BaseType::INT);

    // $area = $w * $h  (LIVE — returned)
    $area = $builder->createBinOp('mul', $w, $h);

    // $perim = ($w + $h) * 2  (DEAD — never used)
    $wPlusH = $builder->createAdd($w, $h);
    $two = new Constant(2, BaseType::INT);
    $perim = $builder->createBinOp('mul', $wPlusH, $two);

    // $diag_sq = $w*$w + $h*$h  (DEAD — cascading: mul and add all dead)
    $wSq = $builder->createBinOp('mul', $w, $w);
    $hSq = $builder->createBinOp('mul', $h, $h);
    $diagSq = $builder->createAdd($wSq, $hSq);

    // return $area
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
// Run the pipeline
// ═══════════════════════════════════════════════════════════════════

$module = buildRectProgram();

// Baseline
$baseline = (new Interpreter())->run($module, 'main');
separator("Baseline: main() = {$baseline}");

echo "Instruction counts (original):\n";
$beforeCounts = [];
foreach ($module->getFunctions() as $fn) {
    $c = countInstructions($fn);
    $beforeCounts[$fn->getName()] = $c;
    echo "  @{$fn->getName()}: {$c}\n";
}

// Show compute() before optimization
separator('Original IR — compute()');
foreach ($module->getFunctions() as $fn) {
    if ($fn->getName() === 'compute') {
        echo implode("\n", $fn->render()) . "\n";
    }
}

// Escape analysis + stack promotion
$escape = new EscapeAnalysisPass();
$escape->run($module);
$promo = new StackPromotionPass();
$promo->run($module, $escape);

// Store-to-load forwarding
$fwd = new StoreToLoadForwardingPass();
$fwd->run($module);

separator('After Forwarding — compute()');
$fwd->printStats();
echo "\n";
foreach ($module->getFunctions() as $fn) {
    if ($fn->getName() === 'compute') {
        echo implode("\n", $fn->render()) . "\n";
    }
}

// Dead instruction elimination
separator('After DCE — compute()');
$dce = new DeadInstructionEliminationPass();
$dce->run($module);
$dce->printStats();
echo "\n";
foreach ($module->getFunctions() as $fn) {
    if ($fn->getName() === 'compute') {
        echo implode("\n", $fn->render()) . "\n";
    }
}

// Differential test
$afterOpt = (new Interpreter())->run($module, 'main');

separator('Results');

echo "Instruction counts:\n";
echo "  ┌─────────────────────────┬────────┬───────┬─────────┐\n";
echo "  │ Function                │ Before │ After │ Removed │\n";
echo "  ├─────────────────────────┼────────┼───────┼─────────┤\n";
foreach ($module->getFunctions() as $fn) {
    $name = $fn->getName();
    $before = $beforeCounts[$name];
    $after = countInstructions($fn);
    $removed = $before - $after;
    echo sprintf("  │ %-23s │ %6d │ %5d │ %7d │\n", $name, $before, $after, $removed);
}
echo "  └─────────────────────────┴────────┴───────┴─────────┘\n\n";

$fwdStats = $fwd->getStats();
echo "Forwarding: {$fwdStats['total_instructions_removed']} instructions\n";
echo "DCE:        {$dce->getEliminated()} instructions\n";
echo "Total:      " . ($fwdStats['total_instructions_removed'] + $dce->getEliminated()) . " instructions removed\n\n";

if ($baseline === $afterOpt) {
    echo "\033[32m✓ Differential test passed: {$baseline} = {$afterOpt}\033[0m\n";
} else {
    echo "\033[31m✗ MISMATCH: baseline={$baseline}, after={$afterOpt}\033[0m\n";
    exit(1);
}
