<?php

declare(strict_types=1);

namespace App\PicoHP;

enum BaseType: string
{
    case INT = 'int';
    case FLOAT = 'float';
    case BOOL = 'bool';
    case STRING = 'string';
    case VOID = 'void';
    case PTR = 'ptr';
    case LABEL = 'label';

    public function toLLVM(): string
    {
        return match($this) {
            BaseType::INT => 'i32',
            BaseType::FLOAT => 'double',
            BaseType::BOOL => 'i1',
            BaseType::VOID => 'void',
            BaseType::STRING, BaseType::PTR => 'ptr',
            default => 'i8*',
        };
    }
}

enum PicoTypeType
{
    case VAR;
    case FUNC;
}

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

    // Class/object support
    protected ?string $className = null;

    /**
     * @param array<BaseType> $params
     */
    public function __construct(BaseType|PicoType $type, PicoTypeType $typeType = PicoTypeType::VAR, array $params = [])
    {
        assert($type instanceof BaseType);
        $this->type = $type;
        $this->typeType = $typeType;
        $this->params = $params;
    }

    public function isEqualTo(PicoType $type): bool
    {
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
        if (preg_match('/^array<[^,]+,\s*(\w+)>$/', $type, $m) === 1) {
            return self::array(self::fromString($m[1]));
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
        assert($this->className !== null, 'getClassName() called on non-object PicoType');
        return $this->className;
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
        assert($this->elementPicoType !== null, 'getElementType() called on non-array PicoType');
        return $this->elementPicoType;
    }

    public function getElementBaseType(): BaseType
    {
        return $this->getElementType()->toBase();
    }

    public function toString(): string
    {
        return ($this->nullable ? '?' : '') . $this->type->value;
    }
}
