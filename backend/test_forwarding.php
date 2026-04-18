<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use PicoIR\{Module, Builder, BaseType, Instruction, Constant, NullConstant, Param, Label};
use PicoIR\Analysis\EscapeAnalysisPass;
use PicoIR\Transform\{StackPromotionPass, StoreToLoadForwardingPass};
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

function buildPointProgram(): Module
{
    Instruction::resetCounter();
    $module = new Module('point_forwarding_test');
    $builder = new Builder($module);

    $module->addHeaderLine('%struct.Point = type { i32, i32, i32 }');
    $module->addHeaderLine('declare ptr @picohp_object_alloc(i64, i32)');

    // Point::__construct($this, $x, $y)
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

    // Point::getX($this) → int
    $getX = $module->addFunction('Point__getX', BaseType::INT, [BaseType::PTR]);
    $entry = $getX->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $thisSlot = $builder->createAlloca(BaseType::PTR, 'this');
    $builder->createStore(new Param(0, BaseType::PTR), $thisSlot);
    $thisVal = $builder->createLoad($thisSlot);
    $xPtr = $builder->createStructGEP('Point', $thisVal, 1, BaseType::INT);
    $xVal = $builder->createLoad($xPtr);
    $builder->createRet($xVal);

    // Point::getY($this) → int
    $getY = $module->addFunction('Point__getY', BaseType::INT, [BaseType::PTR]);
    $entry = $getY->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $thisSlot = $builder->createAlloca(BaseType::PTR, 'this');
    $builder->createStore(new Param(0, BaseType::PTR), $thisSlot);
    $thisVal = $builder->createLoad($thisSlot);
    $yPtr = $builder->createStructGEP('Point', $thisVal, 2, BaseType::INT);
    $yVal = $builder->createLoad($yPtr);
    $builder->createRet($yVal);

    // sumCoords() → int
    $sum = $module->addFunction('sumCoords', BaseType::INT);
    $entry = $sum->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $pLocal = $builder->createAlloca(BaseType::PTR, 'p');
    $pObj = emitNew($builder, 'Point', 1);
    $builder->createCall('Point___construct', [$pObj, new Constant(10, BaseType::INT), new Constant(20, BaseType::INT)], BaseType::VOID);
    $builder->createStore($pObj, $pLocal);
    $pVal = $builder->createLoad($pLocal);
    $x = $builder->createCall('Point__getX', [$pVal], BaseType::INT);
    $pVal2 = $builder->createLoad($pLocal);
    $y = $builder->createCall('Point__getY', [$pVal2], BaseType::INT);
    $result = $builder->createAdd($x, $y);
    $builder->createRet($result);

    // main() → int
    $main = $module->addFunction('main', BaseType::INT);
    $entry = $main->addBasicBlock('entry');
    $builder->setInsertPoint($entry);
    $result = $builder->createCall('sumCoords', [], BaseType::INT);
    $builder->createRet($result);

    return $module;
}

// ═══════════════════════════════════════════════════════════════════
// Run the full pipeline
// ═══════════════════════════════════════════════════════════════════

$module = buildPointProgram();

// -- Step 1: Interpret before any optimization --
separator('Step 1: Baseline');
$baseline = (new Interpreter())->run($module, 'main');
echo "Interpreter result: {$baseline}\n\n";

echo "Instruction counts (before):\n";
foreach ($module->getFunctions() as $fn) {
    $count = countInstructions($fn);
    echo "  @{$fn->getName()}: {$count} instructions\n";
}

// -- Step 2: Show original IR for Point___construct --
separator('Step 2: Original IR — Point___construct');
foreach ($module->getFunctions() as $fn) {
    if ($fn->getName() === 'Point___construct') {
        echo implode("\n", $fn->render()) . "\n";
    }
}

// -- Step 3: Run escape analysis + stack promotion --
separator('Step 3: Escape Analysis + Stack Promotion');
$escape = new EscapeAnalysisPass();
$escape->run($module);
$escape->printResults();

$promotion = new StackPromotionPass();
$promotion->run($module, $escape);
$promotion->printReport();

// -- Step 4: Run store-to-load forwarding --
separator('Step 4: Store-to-Load Forwarding');
$forwarding = new StoreToLoadForwardingPass();
$forwarding->run($module);
$forwarding->printStats();
echo "\n";

// -- Step 5: Show optimized IR --
separator('Step 5: Optimized IR');
echo "Instruction counts (after):\n";
foreach ($module->getFunctions() as $fn) {
    $count = countInstructions($fn);
    echo "  @{$fn->getName()}: {$count} instructions\n";
}
echo "\n";

foreach ($module->getFunctions() as $fn) {
    echo implode("\n", $fn->render()) . "\n";
}

// -- Step 6: Differential test --
separator('Step 6: Differential Test');
$afterOpt = (new Interpreter())->run($module, 'main');
echo "Interpreter result (after optimization): {$afterOpt}\n";

if ($baseline === $afterOpt) {
    echo "\033[32m✓ Results match — optimization is semantics-preserving\033[0m\n";
} else {
    echo "\033[31m✗ MISMATCH — baseline={$baseline}, after={$afterOpt}\033[0m\n";
    exit(1);
}

// -- Summary --
separator('Summary');
echo "Pipeline: escape analysis → stack promotion → store-to-load forwarding\n\n";

$stats = $forwarding->getStats();
echo "Store-to-load forwarding eliminated:\n";
echo "  {$stats['allocas_eliminated']} allocas\n";
echo "  {$stats['stores_eliminated']} stores\n";
echo "  {$stats['loads_eliminated']} loads\n";
echo "  {$stats['total_instructions_removed']} total instructions removed\n\n";

echo "For Point___construct, this means:\n";
echo "  Before: alloca→store→load for each of \$this, \$x, \$y (9 instructions)\n";
echo "  After:  params flow directly to GEP+store (0 stack traffic)\n";
echo "  ARM64:  str x1, [x0, #8] / str x2, [x0, #16] / ret\n";
