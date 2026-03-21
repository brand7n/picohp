<?php

declare(strict_types=1);

namespace App\PicoHP\SymbolTable;

use App\PicoHP\PicoType;

class ClassMetadata
{
    public string $name;

    /** @var array<string, PicoType> property name => type */
    public array $properties = [];

    /** @var array<string, int> property name => struct field index */
    public array $propertyOffsets = [];

    /** @var array<string, Symbol> method name => symbol (with params/return type) */
    public array $methods = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function addProperty(string $name, PicoType $type): int
    {
        $index = count($this->properties);
        $this->properties[$name] = $type;
        $this->propertyOffsets[$name] = $index;
        return $index;
    }

    public function getPropertyIndex(string $name): int
    {
        assert(isset($this->propertyOffsets[$name]), "property {$name} not found on class {$this->name}");
        return $this->propertyOffsets[$name];
    }

    public function getPropertyType(string $name): PicoType
    {
        assert(isset($this->properties[$name]), "property {$name} not found on class {$this->name}");
        return $this->properties[$name];
    }

    /**
     * Returns the LLVM struct field types string for the type definition.
     * e.g., "i32, double, ptr" for a class with int, float, string properties.
     */
    public function toLLVMStructFields(): string
    {
        $fields = [];
        foreach ($this->properties as $type) {
            $fields[] = $type->toBase()->toLLVM();
        }
        return implode(', ', $fields);
    }
}
