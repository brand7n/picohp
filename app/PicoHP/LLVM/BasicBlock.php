<?php

namespace App\PicoHP\LLVM;

use App\PicoHP\Tree\{NodeInterface, NodeTrait};

class BasicBlock implements NodeInterface
{
    use NodeTrait;

    protected string $name;

    /**
     * @var array<IRLine>
     */
    protected array $lines = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->lines[] = new IRLine("{$name}:");
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addLine(IRLine $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * @return array<IRLine>
     */
    public function getLines(): array
    {
        $this->verify();
        return $this->lines;
    }

    public function hasTerminator(): bool
    {
        $lastLine = end($this->lines);
        $trimmed = $lastLine !== false ? trim($lastLine->toString()) : '';
        return str_starts_with($trimmed, 'ret')
            || str_starts_with($trimmed, 'br')
            || str_starts_with($trimmed, 'unreachable')
            || str_starts_with($trimmed, 'switch');
    }

    public function verify(): void
    {
        $lastLine = end($this->lines);
        $trimmed = $lastLine !== false ? trim($lastLine->toString()) : '';
        $valid = str_starts_with($trimmed, 'ret')
            || str_starts_with($trimmed, 'br')
            || str_starts_with($trimmed, 'unreachable')
            || str_starts_with($trimmed, 'switch');
        if (!$valid) {
            throw new \RuntimeException("Basic block {$this->name} must end with ret, br, unreachable, or switch");
        }
    }
}
