<?php

declare(strict_types=1);

namespace PicoIR;

class Module
{
    /** @var list<string> Raw header lines (target triple, declares, struct defs) */
    private array $headerLines = [];

    /** @var list<string> Global constant definitions */
    private array $globals = [];

    /** @var list<Function_> */
    private array $functions = [];

    public function __construct(
        private readonly string $name,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function addHeaderLine(string $line): void
    {
        $this->headerLines[] = $line;
    }

    public function addGlobal(string $line): void
    {
        $this->globals[] = $line;
    }

    /**
     * @param list<BaseType> $paramTypes
     */
    public function addFunction(string $name, BaseType $returnType, array $paramTypes = []): Function_
    {
        $fn = new Function_($name, $returnType, $paramTypes);
        $this->functions[] = $fn;
        return $fn;
    }

    /** @return list<Function_> */
    public function getFunctions(): array
    {
        return $this->functions;
    }

    /** @return list<string> */
    public function render(): array
    {
        $lines = [];
        $lines[] = "; ModuleID = '{$this->name}'";
        $lines[] = '';

        foreach ($this->headerLines as $h) {
            $lines[] = $h;
        }
        if ($this->headerLines !== []) {
            $lines[] = '';
        }

        foreach ($this->globals as $g) {
            $lines[] = $g;
        }
        if ($this->globals !== []) {
            $lines[] = '';
        }

        foreach ($this->functions as $fn) {
            $lines = array_merge($lines, $fn->render());
        }

        return $lines;
    }

    public function dump(): string
    {
        return implode("\n", $this->render()) . "\n";
    }
}
