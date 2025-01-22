<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM\Value;

use App\PicoHP\LLVM\ValueAbstract;
use App\PicoHP\BaseType;

class Param extends ValueAbstract
{
    public function __construct(int $param, BaseType $type)
    {
        parent::__construct($type);
        $this->setName(strval($param));
    }

    public function render(): string
    {
        return "%{$this->getName()}";
    }
}
