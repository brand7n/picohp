<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\LLVM\ValueAbstract;
use App\PicoHP\BaseType;

class Void_ extends ValueAbstract
{
    public function __construct()
    {
        parent::__construct(BaseType::VOID);
    }

    public function render(): string
    {
        throw new \RuntimeException('Cannot render a void value');
    }
}
