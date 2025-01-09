<?php

declare(strict_types=1);

use App\PicoHP\LLVM\{Module, Function_, Type};
use App\PicoHP\LLVM\Value\Constant;

it('generates IR using LLVM Values', function () {
    // Example of using the system
    $module = new Module("test_module");
    $builder = $module->getBuilder();
    $function = new Function_("main", $module);
    $builder->setInsertPoint($function);

    $const1 = new Constant(1, Type::INT);
    $const2 = new Constant(2, Type::INT);
    $const3 = new Constant(3, Type::INT);
    $const4 = new Constant(4, Type::INT);

    $addVal = $builder->createInstruction('add', [$const1, $const2]);

    $mulVal = $builder->createInstruction('mul', [$addVal, $const3]);

    $subVal = $builder->createInstruction('sub', [$mulVal, $const4]);

    $builder->createInstruction('ret', [$subVal], false);

    // $module->print();

    $code = $builder->getLines();

    $startLine = 20;
    expect($code[$startLine++])->toBe('    %add_result1 = add i32 1, 2');
    expect($code[$startLine++])->toBe('    %mul_result2 = mul i32 %add_result1, 3');
    expect($code[$startLine++])->toBe('    %sub_result3 = sub i32 %mul_result2, 4');
    expect($code[$startLine++])->toBe('    ret i32 %sub_result3');
});
