<?php

declare(strict_types=1);

namespace App\PicoHP;

class PicoType
{
    /**
     * @var array<BaseType>
     */
    protected array $params;

    protected PicoTypeType $typeType;
    protected BaseType $type;
    protected bool $nullable = false;

    // Array support
    protected bool $isArray = false;
    protected ?PicoType $elementPicoType = null;
    protected ?BaseType $keyType = null; // null = int keys, STRING = string keys

    // Class/object support
    protected ?string $className = null;

    // Enum support
    protected bool $isEnum = false;

    // Mixed type (any ptr, no type checking)
    protected bool $isMixed = false;

    /**
     * @param array<BaseType> $params
     */
    public function __construct(BaseType|PicoType $type, PicoTypeType $typeType = PicoTypeType::VAR, array $params = [])
    {
        \App\PicoHP\CompilerInvariant::check($type instanceof BaseType);
        $this->type = $type;
        $this->typeType = $typeType;
        $this->params = $params;
    }

    public function isEqualTo(PicoType $type): bool
    {
        // Mixed is compatible with any type
        if ($this->isMixed || $type->isMixed) {
            return true;
        }
        // Enum/object types with matching class names are equal
        if ($this->className !== null && $type->className !== null) {
            return $this->className === $type->className;
        }
        return $this->type === $type->type;
    }

    public function toBase(): BaseType
    {
        return $this->type;
    }

    public static function fromString(string $type): PicoType
    {
        if (str_starts_with($type, '?')) {
            $inner = substr($type, 1);
            $pt = self::fromString($inner);
            $pt->nullable = true;
            return $pt;
        }
        /** @var array<int, string> $m */
        $m = [];
        if (preg_match('/^array<(\w+),\s*(\w+)>$/', $type, $m) === 1) {
            $keyTypeName = $m[1];
            $valTypeName = $m[2];
            $arr = self::array(self::fromString($valTypeName));
            if ($keyTypeName === 'string') {
                $arr->keyType = BaseType::STRING;
            }
            return $arr;
        }
        if ($type === 'mixed') {
            $pt = new PicoType(BaseType::PTR);
            $pt->isMixed = true;
            return $pt;
        }
        if ($type === 'array') {
            // Bare array type without generics — untyped, element type unknown
            return self::array(new PicoType(BaseType::PTR));
        }
        $baseType = BaseType::tryFrom($type);
        if ($baseType !== null) {
            return new PicoType($baseType);
        }
        // Assume it's a class name
        return self::object($type);
    }

    public static function array(PicoType $elementType): PicoType
    {
        $pt = new PicoType(BaseType::PTR);
        $pt->isArray = true;
        $pt->elementPicoType = $elementType;
        return $pt;
    }

    public static function object(string $className): PicoType
    {
        $pt = new PicoType(BaseType::PTR);
        $pt->className = $className;
        return $pt;
    }

    public function isObject(): bool
    {
        return $this->className !== null;
    }

    public function getClassName(): string
    {
        \App\PicoHP\CompilerInvariant::check($this->className !== null, 'getClassName() called on non-object PicoType');
        return $this->className;
    }

    public static function enum(string $name): PicoType
    {
        $pt = new PicoType(BaseType::INT); // enums represented as i32 tags
        $pt->className = $name;
        $pt->isEnum = true;
        return $pt;
    }

    public function isEnum(): bool
    {
        return $this->isEnum;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isArray(): bool
    {
        return $this->isArray;
    }

    public function getElementType(): PicoType
    {
        \App\PicoHP\CompilerInvariant::check($this->elementPicoType !== null, 'getElementType() called on non-array PicoType');
        return $this->elementPicoType;
    }

    public function getElementBaseType(): BaseType
    {
        return $this->getElementType()->toBase();
    }

    public function hasStringKeys(): bool
    {
        return $this->keyType === BaseType::STRING;
    }

    public function isMixed(): bool
    {
        return $this->isMixed;
    }

    public function setStringKeys(): void
    {
        $this->keyType = BaseType::STRING;
    }

    public function toString(): string
    {
        return ($this->nullable ? '?' : '') . $this->type->value;
    }
}
