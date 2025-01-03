<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

class Function_
{
    protected Module $module;
    protected string $name;

    public function __construct(string $name, Module $module)
    {
        $this->name = $name;
        $this->module = $module;
        $this->module->addFunction($this);
    }

    public function getName(): string
    {
        return $this->name;
    }
}
