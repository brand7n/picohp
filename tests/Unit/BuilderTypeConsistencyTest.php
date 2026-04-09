<?php

declare(strict_types=1);

use App\PicoHP\BaseType;
use App\PicoHP\LLVM\Builder;
use App\PicoHP\LLVM\Module;
use App\PicoHP\LLVM\Value\{Constant, Instruction};
use App\PicoHP\PicoType;

/**
 * Helper: set up a module with a function + entry block, return the builder.
 */
function makeBuilder(): Builder
{
    $module = new Module('type_test');
    $builder = $module->getBuilder();
    $fn = $module->addFunction('test_fn', returnType: PicoType::fromString('void'));
    $bb = $fn->addBasicBlock('entry');
    $builder->setInsertPoint($bb);
    Instruction::resetCounter();
    return $builder;
}

/**
 * Helper: create a Mockery mock of ValueAbstract with a given type and render string.
 */
function mockVal(BaseType $type, string $renderStr): App\PicoHP\LLVM\ValueAbstract
{
    $mock = Mockery::mock(App\PicoHP\LLVM\ValueAbstract::class);
    $mock->shouldReceive('getType')->andReturn($type);
    $mock->shouldReceive('render')->andReturn($renderStr);
    $mock->shouldReceive('getName')->andReturn('mock');
    return $mock;
}

/**
 * Helper: terminate the current block and return all emitted IR lines as a string.
 */
function getEmittedIR(Builder $builder): string
{
    $builder->createRetVoid();
    $bb = $builder->getCurrentBasicBlock();
    assert($bb !== null);
    $lines = array_map(fn ($l) => $l->toString(), $bb->getLines());
    return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// createStore: verify IR uses rval's type for the store instruction
// ---------------------------------------------------------------------------

it('emits store i32 when rval is INT', function () {
    $builder = makeBuilder();
    $rval = mockVal(BaseType::INT, '42');
    $lval = mockVal(BaseType::INT, '%x_localptr1');

    $builder->createStore($rval, $lval);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('store i32 42, i32* %x_localptr1');
});

it('emits store ptr when rval is PTR', function () {
    $builder = makeBuilder();
    $rval = mockVal(BaseType::PTR, '%str1');
    $lval = mockVal(BaseType::PTR, '%s_localptr1');

    $builder->createStore($rval, $lval);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('store ptr %str1, ptr %s_localptr1');
});

it('emits store double when rval is FLOAT', function () {
    $builder = makeBuilder();
    $rval = mockVal(BaseType::FLOAT, '0x4000000000000000');
    $lval = mockVal(BaseType::FLOAT, '%f_localptr1');

    $builder->createStore($rval, $lval);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('store double 0x4000000000000000, double* %f_localptr1');
});

it('emits store i1 when rval is BOOL', function () {
    $builder = makeBuilder();
    $rval = mockVal(BaseType::BOOL, '1');
    $lval = mockVal(BaseType::BOOL, '%b_localptr1');

    $builder->createStore($rval, $lval);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('store i1 1, i1* %b_localptr1');
});

// ---------------------------------------------------------------------------
// createLoad: verify IR uses the alloca's type for load
// ---------------------------------------------------------------------------

it('emits load i32 from INT alloca', function () {
    $builder = makeBuilder();
    $alloca = $builder->createAlloca('x', BaseType::INT);

    $loaded = $builder->createLoad($alloca);

    expect($loaded->getType())->toBe(BaseType::INT);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('load i32, ptr');
});

it('emits load ptr from PTR alloca', function () {
    $builder = makeBuilder();
    $alloca = $builder->createAlloca('s', BaseType::PTR);

    $loaded = $builder->createLoad($alloca);

    expect($loaded->getType())->toBe(BaseType::PTR);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('load ptr, ptr');
});

it('emits load i1 from BOOL alloca', function () {
    $builder = makeBuilder();
    $alloca = $builder->createAlloca('flag', BaseType::BOOL);

    $loaded = $builder->createLoad($alloca);

    expect($loaded->getType())->toBe(BaseType::BOOL);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('load i1, ptr');
});

// ---------------------------------------------------------------------------
// createArrayPush: verify i32→ptr coercion when element type is PTR
// ---------------------------------------------------------------------------

it('coerces INT to PTR via inttoptr in array push', function () {
    $builder = makeBuilder();
    $arr = mockVal(BaseType::PTR, '%arr1');
    $val = mockVal(BaseType::INT, '%int_val');

    $builder->createArrayPush($arr, $val, BaseType::PTR);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('inttoptr i32 %int_val to ptr');
    expect($ir)->toContain('call void @pico_array_push_ptr(ptr %arr1, ptr');
});

it('does not coerce PTR value for PTR array push', function () {
    $builder = makeBuilder();
    $arr = mockVal(BaseType::PTR, '%arr1');
    $val = mockVal(BaseType::PTR, '%ptr_val');

    $builder->createArrayPush($arr, $val, BaseType::PTR);

    $ir = getEmittedIR($builder);
    expect($ir)->not->toContain('inttoptr');
    expect($ir)->toContain('call void @pico_array_push_ptr(ptr %arr1, ptr %ptr_val)');
});

it('emits array push with correct type suffix for INT elements', function () {
    $builder = makeBuilder();
    $arr = mockVal(BaseType::PTR, '%arr1');
    $val = mockVal(BaseType::INT, '%int_val');

    $builder->createArrayPush($arr, $val, BaseType::INT);

    $ir = getEmittedIR($builder);
    expect($ir)->not->toContain('inttoptr');
    expect($ir)->toContain('call void @pico_array_push_int(ptr %arr1, i32 %int_val)');
});

// ---------------------------------------------------------------------------
// createArraySet: verify i32→ptr coercion when element type is PTR
// ---------------------------------------------------------------------------

it('coerces BOOL to PTR via inttoptr in array set', function () {
    $builder = makeBuilder();
    $arr = mockVal(BaseType::PTR, '%arr1');
    $idx = mockVal(BaseType::INT, '0');
    $val = mockVal(BaseType::BOOL, '%bool_val');

    $builder->createArraySet($arr, $idx, $val, BaseType::PTR);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('inttoptr i1 %bool_val to ptr');
    expect($ir)->toContain('call void @pico_array_set_ptr(ptr %arr1, i32 0, ptr');
});

// ---------------------------------------------------------------------------
// createArrayGet: verify return type matches element type
// ---------------------------------------------------------------------------

it('returns INT value from array get with INT element type', function () {
    $builder = makeBuilder();
    $arr = mockVal(BaseType::PTR, '%arr1');
    $idx = mockVal(BaseType::INT, '0');

    $result = $builder->createArrayGet($arr, $idx, BaseType::INT);

    expect($result->getType())->toBe(BaseType::INT);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('call i32 @pico_array_get_int(');
});

it('returns PTR value from array get with PTR element type', function () {
    $builder = makeBuilder();
    $arr = mockVal(BaseType::PTR, '%arr1');
    $idx = mockVal(BaseType::INT, '0');

    $result = $builder->createArrayGet($arr, $idx, BaseType::PTR);

    expect($result->getType())->toBe(BaseType::PTR);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('call ptr @pico_array_get_ptr(');
});

// ---------------------------------------------------------------------------
// createCallPrintf: format string selection by type
// ---------------------------------------------------------------------------

it('uses %d format for INT values in printf', function () {
    $builder = makeBuilder();
    $val = mockVal(BaseType::INT, '%x');

    $builder->createCallPrintf($val);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('@.str.d');
    expect($ir)->toContain('i32 %x');
});

it('uses %s format for PTR values in printf', function () {
    $builder = makeBuilder();
    $val = mockVal(BaseType::PTR, '%s');

    $builder->createCallPrintf($val);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('@.str.s');
    expect($ir)->toContain('ptr %s');
});

it('uses %s format for STRING values in printf', function () {
    $builder = makeBuilder();
    $val = mockVal(BaseType::STRING, '%str');

    $builder->createCallPrintf($val);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('@.str.s');
    expect($ir)->toContain('ptr %str');
});

it('uses %.14g format for FLOAT values in printf', function () {
    $builder = makeBuilder();
    $val = mockVal(BaseType::FLOAT, '%f');

    $builder->createCallPrintf($val);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('@.str.f');
    expect($ir)->toContain('double %f');
});

// ---------------------------------------------------------------------------
// Type conversions: verify output types
// ---------------------------------------------------------------------------

it('createPtrToInt produces INT from PTR', function () {
    $builder = makeBuilder();
    $val = mockVal(BaseType::PTR, '%p');

    $result = $builder->createPtrToInt($val);

    expect($result->getType())->toBe(BaseType::INT);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('ptrtoint ptr %p to i32');
});

it('createSiToFp produces FLOAT from INT', function () {
    $builder = makeBuilder();
    $val = mockVal(BaseType::INT, '%i');

    $result = $builder->createSiToFp($val);

    expect($result->getType())->toBe(BaseType::FLOAT);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('sitofp i32 %i to double');
});

it('createFpToSi produces INT from FLOAT', function () {
    $builder = makeBuilder();
    $val = mockVal(BaseType::FLOAT, '%f');

    $result = $builder->createFpToSi($val);

    expect($result->getType())->toBe(BaseType::INT);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('fptosi double %f to i32');
});

it('createZext produces INT from BOOL', function () {
    $builder = makeBuilder();
    $val = mockVal(BaseType::BOOL, '%b');

    $result = $builder->createZext($val);

    expect($result->getType())->toBe(BaseType::INT);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('zext i1 %b to i32');
});

// ---------------------------------------------------------------------------
// createNullCheck: non-pointer types short-circuit to false
// ---------------------------------------------------------------------------

it('returns constant 0 for null check on INT', function () {
    $builder = makeBuilder();
    $val = mockVal(BaseType::INT, '%x');

    $result = $builder->createNullCheck($val);

    expect($result)->toBeInstanceOf(Constant::class);
    expect($result->getType())->toBe(BaseType::BOOL);
    expect($result->render())->toBe('0');
});

it('emits icmp eq for null check on PTR', function () {
    $builder = makeBuilder();
    $val = mockVal(BaseType::PTR, '%p');

    $result = $builder->createNullCheck($val);

    expect($result->getType())->toBe(BaseType::BOOL);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('icmp eq ptr %p, null');
});

// ---------------------------------------------------------------------------
// createCall: parameter types emitted correctly
// ---------------------------------------------------------------------------

it('emits correct parameter types in function call', function () {
    $builder = makeBuilder();
    $p1 = mockVal(BaseType::INT, '%a');
    $p2 = mockVal(BaseType::PTR, '%b');
    $p3 = mockVal(BaseType::FLOAT, '%c');

    $result = $builder->createCall('my_func', [$p1, $p2, $p3], BaseType::INT);

    expect($result->getType())->toBe(BaseType::INT);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('call i32 @my_func');
    expect($ir)->toContain('i32 %a');
    expect($ir)->toContain('ptr %b');
    expect($ir)->toContain('double %c');
});

it('emits void call without result assignment', function () {
    $builder = makeBuilder();
    $p1 = mockVal(BaseType::PTR, '%s');

    $result = $builder->createCall('print_str', [$p1], BaseType::VOID);

    expect($result)->toBeInstanceOf(App\PicoHP\LLVM\Value\Void_::class);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('call void @print_str');
    expect($ir)->not->toContain('= call');
});

// ---------------------------------------------------------------------------
// createSelect: verify type propagation from true branch
// ---------------------------------------------------------------------------

it('propagates type through select instruction', function () {
    $builder = makeBuilder();
    $cond = mockVal(BaseType::BOOL, '%cond');
    $trueVal = mockVal(BaseType::INT, '%t');
    $falseVal = mockVal(BaseType::INT, '%f');

    $result = $builder->createSelect($cond, $trueVal, $falseVal);

    expect($result->getType())->toBe(BaseType::INT);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('select i1 %cond, i32 %t, i32 %f');
});

// ---------------------------------------------------------------------------
// BOOL array element type uses i32 calling convention
// ---------------------------------------------------------------------------

it('uses i32 calling convention for BOOL array elements', function () {
    $builder = makeBuilder();
    $arr = mockVal(BaseType::PTR, '%arr');
    $val = mockVal(BaseType::BOOL, '%b');

    $builder->createArrayPush($arr, $val, BaseType::BOOL);

    $ir = getEmittedIR($builder);
    // BOOL arrays use i32 ABI (not i1) to match the runtime
    expect($ir)->toContain('call void @pico_array_push_bool(ptr %arr, i32 %b)');
});

it('returns i32 from BOOL array get to match runtime ABI', function () {
    $builder = makeBuilder();
    $arr = mockVal(BaseType::PTR, '%arr');
    $idx = mockVal(BaseType::INT, '0');

    $result = $builder->createArrayGet($arr, $idx, BaseType::BOOL);

    // The return value type is BOOL (semantic), but runtime uses i32
    expect($result->getType())->toBe(BaseType::BOOL);

    $ir = getEmittedIR($builder);
    expect($ir)->toContain('call i32 @pico_array_get_bool(');
});

// ---------------------------------------------------------------------------
// Store type consistency: STRING maps to ptr (same as PTR)
// ---------------------------------------------------------------------------

it('emits store ptr when rval is STRING', function () {
    $builder = makeBuilder();
    $rval = mockVal(BaseType::STRING, '%str1');
    $lval = mockVal(BaseType::STRING, '%s_localptr1');

    $builder->createStore($rval, $lval);

    $ir = getEmittedIR($builder);
    // STRING and PTR both map to ptr in LLVM
    expect($ir)->toContain('store ptr %str1, ptr %s_localptr1');
});

// ---------------------------------------------------------------------------
// createArraySet: INT value with INT element type — no coercion needed
// ---------------------------------------------------------------------------

it('does not coerce INT value for INT array set', function () {
    $builder = makeBuilder();
    $arr = mockVal(BaseType::PTR, '%arr');
    $idx = mockVal(BaseType::INT, '5');
    $val = mockVal(BaseType::INT, '%v');

    $builder->createArraySet($arr, $idx, $val, BaseType::INT);

    $ir = getEmittedIR($builder);
    expect($ir)->not->toContain('inttoptr');
    expect($ir)->toContain('call void @pico_array_set_int(ptr %arr, i32 5, i32 %v)');
});
