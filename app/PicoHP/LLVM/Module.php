<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

use App\PicoHP\LLVM\Value\Instruction;
use App\PicoHP\Tree\{NodeInterface, NodeTrait};

// information about our module
class Module implements NodeInterface
{
    use NodeTrait;

    protected string $name;
    protected Builder $builder;

    /**
     * @var array<IRLine>
     */
    protected array $globalLines = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->builder = new Builder($this, "arm64-apple-macosx14.0.0", "e-m:o-i64:64-i128:128-n32:64-S128");
        Instruction::resetCounter();
    }

    /**
     * @param array<string> $params
     */
    public function addFunction(string $name, array $params = []): Function_
    {
        $f = new Function_($name, $params);
        $this->addChild($f);
        return $f;
    }

    public function getBuilder(): Builder
    {
        return $this->builder;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addLine(IRLine $line): void
    {
        $this->globalLines[] = $line;
    }

    /**
     * @return array<IRLine>
     */
    public function getLines(): array
    {
        $code = $this->globalLines;

        // render out functions and blocks within
        foreach ($this->getChildren() as $function) {
            assert($function instanceof Function_);
            $code = array_merge($code, $function->getLines());
        }

        return $code;
    }

    /**
     * @param resource $file
     */
    public function print($file = STDOUT): void
    {
        foreach ($this->getLines() as $line) {
            fwrite($file, $line->toString() . PHP_EOL);
        }
    }
}
