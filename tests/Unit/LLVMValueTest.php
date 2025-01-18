<?php

declare(strict_types=1);

use App\PicoHP\LLVM\{Module, Type};
use App\PicoHP\LLVM\Value\{Constant, Instruction, Global_, Void_};

it('generates IR using LLVM Values', function () {
    // Example of using the system
    $module = new Module("test_module");
    $builder = $module->getBuilder();
    $function = $module->addFunction("main");
    $bb = $function->addBasicBlock("entry");
    expect($bb->getName())->toBe('entry');
    $builder->setInsertPoint($bb);

    Instruction::resetCounter();

    $const1 = new Constant(1, Type::INT);
    $const2 = new Constant(2, Type::INT);
    $const3 = new Constant(3, Type::INT);
    $const4 = new Constant(4, Type::INT);
    expect($const1->render())->toBe('1');
    expect($const2->getValue())->toBe(2);

    $addVal = $builder->createInstruction('add', [$const1, $const2]);

    $mulVal = $builder->createInstruction('mul', [$addVal, $const3]);

    $subVal = $builder->createInstruction('sub', [$mulVal, $const4]);

    $builder->createInstruction('ret', [$subVal], false);

    $code = [];
    $functions = $module->getChildren();
    expect(count($functions))->toBe(1);
    $function = $functions[0];
    assert($function instanceof App\PicoHP\LLVM\Function_);
    expect($function->getName())->toBe('main');
    foreach ($function->getLines() as $line) {
        $code[] = $line->toString();
    }
    $startLine = 2;
    expect($code[$startLine++])->toBe('    %add_result1 = add i32 1, 2');
    expect($code[$startLine++])->toBe('    %mul_result2 = mul i32 %add_result1, 3');
    expect($code[$startLine++])->toBe('    %sub_result3 = sub i32 %mul_result2, 4');
    expect($code[$startLine++])->toBe('    ret i32 %sub_result3');
});

it('throws an exception on an invalid block', function () {
    $module = new Module("test_module");
    $builder = $module->getBuilder();
    $function = $module->addFunction("main");
    $bb = $function->addBasicBlock("entry");
    $builder->setInsertPoint($bb);
    $builder->createInstruction('add', [new Constant(1, Type::INT), new Constant(2, Type::INT)]);
    $bb->getLines();
})->throws(\RuntimeException::class, 'Basic block must end with ret or br');

it('can create a global value', function () {
    $global = new Global_('my_global', Type::INT->value);
    expect($global->render())->toBe('@my_global');
});

it('can create a void value', function () {
    $void = new Void_();
    $void->render();
})->throws(\RuntimeException::class, 'Cannot render a void value');

// Copilot generated test:

// it('generates IR using LLVM Values with phi nodes', function () {
//     // Example of using the system
//     $module = new Module("test_module");
//     $builder = $module->getBuilder();
//     $function = $module->addFunction("main");
//     $bb1 = $function->addBasicBlock("entry");
//     $bb2 = $function->addBasicBlock("if.then");
//     $bb3 = $function->addBasicBlock("if.else");
//     $bb4 = $function->addBasicBlock("if.end");
//     $builder->setInsertPoint($bb1);

//     Instruction::resetCounter();

//     $const1 = new Constant(1, Type::INT);
//     $const2 = new Constant(2, Type::INT);
//     $const3 = new Constant(3, Type::INT);
//     $const4 = new Constant(4, Type::INT);

//     $addVal = $builder->createInstruction('add', [$const1, $const2]);

//     $cmpVal = $builder->createInstruction('icmp eq', [$addVal, $const3]);

//     $builder->createInstruction('br', [$cmpVal, $bb2, $bb3]);

//     $builder->setInsertPoint($bb2);
//     $builder->createInstruction('br', [$bb4]);

//     $builder->setInsertPoint($bb3);
//     $builder->createInstruction('br', [$bb4]);

//     $builder->setInsertPoint($bb4);
//     $phiVal = $builder->createInstruction('phi', [Type::INT, ['%add_result1', $bb2], ['%sub_result3', $bb3]]);

//     $subVal = $builder->createInstruction('sub', [$phiVal, $const4]);

//     $builder->createInstruction('ret', [$subVal], false);

//     $code = [];
//     $functions = $module->getChildren();
//     expect(count($functions))->toBe(1);
//     $function = $functions[0];
//     assert($function instanceof App\PicoHP\LLVM\Function_);
//     expect($function->getName())->toBe('main');
//     foreach ($function->getLines() as $line) {
//         $code[] = $line->toString();
//     }
//     $startLine = 2;
//     expect($code[$startLine++])->toBe('    %add_result1 = add i32 1
