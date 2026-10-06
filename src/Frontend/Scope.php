<?php

declare(strict_types=1);

namespace PicoIR\Frontend;

use PicoIR\BaseType;
use PicoIR\Instruction\AllocaInstruction;

final class Scope
{
    /** @var array<string, AllocaInstruction> */
    private array $vars = [];

    public function __construct(
        private readonly ?Scope $parent = null,
    ) {}

    public function define(string $name, AllocaInstruction $slot): void
    {
        $this->vars[$name] = $slot;
    }

    public function lookup(string $name): ?AllocaInstruction
    {
        return $this->vars[$name] ?? $this->parent?->lookup($name);
    }
}
