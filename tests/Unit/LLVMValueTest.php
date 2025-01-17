<?php

declare(strict_types=1);

use App\PicoHP\LLVM\{Module, Type};
use App\PicoHP\LLVM\Value\Constant;

it('generates IR using LLVM Values', function () {
    // Example of using the system
    $module = new Module("test_module");
    $builder = $module->getBuilder();
    $function = $module->addFunction("main");
    $bb = $function->addBasicBlock("entry");
    $builder->setInsertPoint($bb);

    $const1 = new Constant(1, Type::INT);
    $const2 = new Constant(2, Type::INT);
    $const3 = new Constant(3, Type::INT);
    $const4 = new Constant(4, Type::INT);

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
