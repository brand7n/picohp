<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\BaseType;
use App\PicoHP\LLVM\ValueAbstract;

class NullConstant extends ValueAbstract
{
    public function __construct()
    {
        parent::__construct(BaseType::STRING);
    }

    public function render(): string
    {
        return 'null';
    }
}
