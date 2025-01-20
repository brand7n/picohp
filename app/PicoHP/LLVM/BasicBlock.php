<?php

namespace App\PicoHP\LLVM;

use Illuminate\Support\Str;
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

    public function verify(): void
    {
        $lastLine = end($this->lines);
        if ($lastLine === false || !Str::startsWith(trim($lastLine->toString()), ['ret', 'br'])) {
            throw new \RuntimeException("Basic block {$this->name} must end with ret or br");
        }
    }
}
