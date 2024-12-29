<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

class EchoEmitter extends BaseEmitter
{
    public function __construct()
    {

    }

    // method declaration
    public function begin(): void
    {

    }

    // method declaration
    public function end(): void
    {
        // $this->writeln(0, '%hello_world_ptr = getelementptr [14 x i8], [14 x i8]* @.hello_world, i32 0, i32 0');
        // $this->writeln(0, 'call void @println(i8* %hello_world_ptr)');

        // test output
        $this->writeln(1, '%1 = call i32 @putchar(i32 noundef 49)');
    }
}
