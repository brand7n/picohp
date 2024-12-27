<?php

declare(strict_types=1);

namespace App\LLVM;

class FunctionEmitter extends BaseEmitter
{
    private string $name;

    /**
     * @var array<string>
     */
    private array $params;

    /**
     * @param  array<string>  $params
     */
    public function __construct(string $name, array $params)
    {
        if (strlen($name) === 0) {
            throw new \Exception('function name missing');
        }
        $this->name = $name;
        $this->params = $params;
    }

    // method declaration
    public function begin(): void
    {
        if ($this->name === 'main') {
            $this->restart();
            $this->writeln(0, 'target datalayout = "e-m:o-i64:64-i128:128-n32:64-S128"');
            $this->writeln(0, 'target triple = "arm64-apple-macosx14.0.0"');
            $this->writeln();
            $this->writeln(0, 'declare i32 @putchar(i32)');
            $this->writeln();
        }
        $paramList = implode(', ', $this->params);
        $this->writeln(0, "define i32 @{$this->name}({$paramList}) {");
        $this->writeln(1, '; here');
    }

    // method declaration
    public function end(): void
    {
        $this->writeln(1, 'ret i32 0');
        $this->writeln(0, '}');
        $this->writeln();
    }
}
