<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\LLVM\ValueAbstract;

class Global_ extends ValueAbstract
{
    public function __construct(string $name, string $type)
    {
        parent::__construct($type);
        $this->setName(str_replace(' ', '', $name));
    }

    public function render(): string
    {
        return "@{$this->getName()}";
    }
}
