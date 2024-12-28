<?php

namespace App\Parser;

class Symbol {
    public string $name;
    public string $type;
    public mixed $value;
    public int $scopeLevel;

    public function __construct(string $name, string $type, mixed $value = null, int $scopeLevel = 0) {
        $this->name = $name;
        $this->type = $type;
        $this->value = $value;
        $this->scopeLevel = $scopeLevel;
    }

    public function __toString(): string {
        return sprintf(
            "Symbol(name: %s, type: %s, value: %s, scopeLevel: %d)",
            $this->name,
            $this->type,
            var_export($this->value, true),
            $this->scopeLevel
        );
    }
}