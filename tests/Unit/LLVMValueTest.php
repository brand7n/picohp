<?php

declare(strict_types=1);

use App\PicoHP\LLVM\Value\{Constant, Instruction};

it('generates IR from LLVM Values', function () {

    // Example of using the system
    $const1 = new Constant(10, 'i32');
    $const2 = new Constant(20, 'i32');

    // Create an addition instruction using the constants
    $addInstruction = new Instruction('add', [$const1, $const2], 'i32');
    $addInstruction->setName('add_result');

    expect($addInstruction->__toString())->toBe('%add_result = add i32 10 i32, 20 i32');

    // Generate a function definition (e.g., a function returning i32 with no arguments)
    // $funcDef = new FunctionDefinition('myFunction', 'i32');
    // $funcDef->addArgument('i32');  // Adding one argument of type i32 (int)

    // echo $funcDef . "\n";

    // Output the addition instruction as LLVM IR
    //echo "  " . $addInstruction . "\n";

    // Simulate returning a result from the function
    //echo "  ret i32 %" . $addInstruction->getName() . "\n";
    //echo "}\n";
});
