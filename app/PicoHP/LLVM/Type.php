<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

enum Type: string
{
    case INT = 'i32';
    case FLOAT = 'float';
    case BOOL = 'i1';
    case STRING = '[100 x i8]';
}
