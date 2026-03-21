<?php

declare(strict_types=1);

namespace App\PicoHP\SymbolTable;

use App\PicoHP\PicoType;

class ClassMetadata
{
    public string $name;
    public ?string $parentName = null;

    /** @var array<string, PicoType> property name => type */
    public array $properties = [];

    /** @var array<string, int> property name => struct field index */
    public array $propertyOffsets = [];

    /** @var array<string, Symbol> method name => symbol (with params/return type) */
    public array $methods = [];

    /** @var array<string, string> method name => defining class name (for qualified call) */
    public array $methodOwner = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function inheritFrom(ClassMetadata $parent): void
    {
        $this->parentName = $parent->name;
        // Copy parent properties first (preserving offsets)
        foreach ($parent->properties as $propName => $propType) {
            $this->properties[$propName] = $propType;
            $this->propertyOffsets[$propName] = $parent->propertyOffsets[$propName];
        }
        // Copy parent methods (child can override later)
        foreach ($parent->methods as $methodName => $methodSymbol) {
            $this->methods[$methodName] = $methodSymbol;
            $this->methodOwner[$methodName] = $parent->methodOwner[$methodName] ?? $parent->name;
        }
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
