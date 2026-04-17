<?php

declare(strict_types=1);

namespace PicoIR;

abstract class ValueAbstract
{
    protected ?string $name = null;

    public function __construct(protected BaseType $type) {}

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getType(): BaseType
    {
        return $this->type;
    }

    /** Render as an operand reference (e.g. %add_result5, i32 42, null). */
    abstract public function renderRef(): string;
}
