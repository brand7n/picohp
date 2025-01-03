<?php

declare(strict_types=1);

use App\PicoHP\LLVM\Builder;
use App\PicoHP\LLVM\Value\Constant;

it('generates IR using LLVM Values', function () {
    // Example of using the system
    $builder = new Builder("arm64-apple-macosx14.0.0", "e-m:o-i64:64-i128:128-n32:64-S128");

    $const1 = new Constant(10, 'i32');
    $const2 = new Constant(20, 'i32');
    $const3 = new Constant(30, 'i32');
    $const4 = new Constant(40, 'i32');

    $addVal = $builder->createInstruction('add', [$const1, $const2]);

    $mulVal = $builder->createInstruction('mul', [$addVal, $const3]);

    $subVal = $builder->createInstruction('sub', [$mulVal, $const4]);

    $builder->createInstruction('ret', [$subVal], false);

    //$builder->print();

    $code = $builder->getLines();

    $startLine = 19;
    expect(count($code))->toBe($startLine + 4);
    expect($code[$startLine++])->toBe('%add_result1 = add i32 10 i32, 20 i32');
    expect($code[$startLine++])->toBe('%mul_result2 = mul i32 %add_result1, 30 i32');
    expect($code[$startLine++])->toBe('%sub_result3 = sub i32 %mul_result2, 40 i32');
    expect($code[$startLine++])->toBe('ret i32 %sub_result3');
});
