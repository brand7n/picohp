<?php

namespace App\Parser;

class Symbol
{
    public string $name;
    public string $type;
    public mixed $value;

    public function __construct(string $name, string $type, mixed $value = null)
    {
        $this->name = $name;
        $this->type = $type;
        $this->value = $value;
    }

    public function __toString(): string
    {
        return sprintf(
            "Symbol(name: %s, type: %s, value: %s)",
            $this->name,
            $this->type,
            var_export($this->value, true),
        );
    }
}
