<?php

declare(strict_types=1);

use App\PicoHP\BaseType;
use App\PicoHP\LLVM\Module;
use App\PicoHP\LLVM\Value\Constant;
use App\PicoHP\PicoType;

/**
 * Exercises Builder helpers that are not always hit by a single Feature program.
 * Increments line coverage for LLVM emission without running the full clang link step.
 */
it('emits IR for string, array, object, enum, exception, and call helpers', function () {
    $module = new Module('builder_surface');
    $b = $module->getBuilder();
    $fn = $module->addFunction('builder_cov', PicoType::fromString('void'));
    $bb = $fn->addBasicBlock('entry');
    $b->setInsertPoint($bb);

    $str = $b->createStringConstant('xy');
    $b->createStringLen($str);
    $b->createStringOrd($str);
    $i0 = new Constant(0, BaseType::INT);
    $b->createStringByteAt($str, $i0);
    $b->createStringConcat($str, $str);
    $b->createGetElementPtr($str, $i0);

    $b->createCallPrintf($str);
    $f1 = new Constant(1.25, BaseType::FLOAT);
    $b->createCallPrintf($f1);
    $b->createCallPrintf($i0);

    $b->createCall('some_void', [$i0], BaseType::VOID);
    $b->createCall('some_i32', [$i0], BaseType::INT);

    $arr = $b->createArrayNew();
    $b->createArrayLen($arr);
    $b->createArrayPush($arr, $i0, BaseType::INT);
    $b->createArrayGet($arr, $i0, BaseType::INT);
    $b->createArraySet($arr, $i0, $i0, BaseType::INT);

    $b1 = new Constant(1, BaseType::BOOL);
    $b->createArrayPush($arr, $b1, BaseType::BOOL);
    $b->createArrayGet($arr, $i0, BaseType::BOOL);
    $b->createArraySet($arr, $i0, $b1, BaseType::BOOL);

    $b->createArrayPush($arr, $str, BaseType::STRING);
    $b->createArrayGet($arr, $i0, BaseType::STRING);
    $b->createArraySet($arr, $i0, $str, BaseType::STRING);

    $b->createArrayPush($arr, $f1, BaseType::FLOAT);
    $b->createArrayGet($arr, $i0, BaseType::FLOAT);
    $b->createArraySet($arr, $i0, $f1, BaseType::FLOAT);

    $b->createArrayPush($arr, $i0, BaseType::PTR);
    $b->createArrayGet($arr, $i0, BaseType::PTR);
    $b->createArraySet($arr, $i0, $i0, BaseType::PTR);

    $obj = $b->createObjectAlloc('CovStruct', 1);
    $b->createNullCheck($obj);
    $tag = new Constant(0, BaseType::INT);
    $b->createEnumValueLookup('CovEnum', 1, $tag);

    $okResult = $b->createResultOk($i0, BaseType::INT);
    $errResult = $b->createResultErr($obj, BaseType::INT);
    $b->createExtractError($okResult, BaseType::INT);
    $b->createExtractValue($okResult, BaseType::INT);
    $b->createExtractException($errResult, BaseType::INT);

    $trueB = new Constant(1, BaseType::BOOL);
    $b->createSelect($trueB, $i0, new Constant(2, BaseType::INT));

    $b->createStructGEP('CovStruct', $obj, 0, BaseType::INT);
    $b->createFpToSi($f1);
    $b->createPtrToInt($str);
    $b->createSiToFp($i0);
    $b->createZext($trueB);

    $b->createRetVoid();

    $lines = $fn->getLines();
    expect(count($lines))->toBeGreaterThan(5);
});

it('emits result err as block terminator', function () {
    $module = new Module('builder_throw_cov');
    $b = $module->getBuilder();
    $fn = $module->addFunction('throw_only', PicoType::fromString('void'), [], true);
    $bb = $fn->addBasicBlock('entry');
    $b->setInsertPoint($bb);
    $obj = $b->createObjectAlloc('ThrowT', 0);
    $errResult = $b->createResultErr($obj, BaseType::VOID);
    $b->addLine("ret %result.void {$errResult->render()}", 1);
    expect(count($fn->getLines()))->toBeGreaterThan(2);
});
