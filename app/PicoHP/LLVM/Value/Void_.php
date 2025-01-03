<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\LLVM\ValueAbstract;

class Void_ extends ValueAbstract
{
    public function __construct()
    {
        parent::__construct('void');
    }

    public function render(): string
    {
        throw new \Exception("don't render void");
    }
}
