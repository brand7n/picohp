<?php

declare(strict_types=1);

namespace App\PicoHP\LLVM;

/*
TODO: instead of implementing every instruction as a Value, consider larger chunks (like inline functions), or a generic binary_op form
*/
// Base class for all values
abstract class ValueAbstract
{
    protected ?string $name = null;
    protected string $type;

    // Constructor to set the type of the value
    public function __construct(string $type)
    {
        $this->type = $type;
    }

    // Set the name of the value (for debugging or identification)
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    // Get the name of the value
    public function getName(): ?string
    {
        return $this->name;
    }

    // Get the type of the value
    public function getType(): string
    {
        return $this->type;
    }

    abstract public function render(): string;
}
