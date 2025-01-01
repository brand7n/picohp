<?php

declare(strict_types=1);

use App\PicoHP\LLVM\Value\{Constant, Instruction};

it('generates IR from LLVM Values', function () {

    // Example of using the system
    $const1 = new Constant(10, 'i32');
    $const2 = new Constant(20, 'i32');
    $const3 = new Constant(30, 'i32');
    $const4 = new Constant(40, 'i32');

    // Create an addition instruction using the constants
    $addInstruction = new Instruction('add', [$const1, $const2], 'i32');
    $addInstruction->setName('add_result');

    $mulInstruction = new Instruction('mul', [$addInstruction, $const3], 'i32');
    $mulInstruction->setName('mul_result');

    $subInstruction = new Instruction('sub', [$mulInstruction, $const4], 'i32');
    $subInstruction->setName('sub_result');

    $code = $subInstruction->renderCode();

    expect(count($code))->toBe(3);
    expect($code[0])->toBe('%add_result = add i32 10 i32, 20 i32');
    expect($code[1])->toBe('%mul_result = mul i32 %add_result, 30 i32');
    expect($code[2])->toBe('%sub_result = sub i32 %mul_result, 40 i32');
});
