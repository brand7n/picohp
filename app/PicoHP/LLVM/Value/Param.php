<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\LLVM\ValueAbstract;

class Param extends ValueAbstract
{
    public function __construct(int $param, string $type)
    {
        parent::__construct($type);
        $this->setName(strval($param));
    }

    public function render(): string
    {
        return "%{$this->getName()}";
    }
}
