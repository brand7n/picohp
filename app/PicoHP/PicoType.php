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
            BaseType::STRING => "ptr",
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
    protected ?BaseType $elementType = null;
    protected int $arraySize = 0;

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
            $pt = new PicoType(BaseType::from($inner));
            $pt->nullable = true;
            return $pt;
        }
        if (preg_match('/^array<[^,]+,\s*(\w+)>$/', $type, $m) === 1) {
            return self::array(BaseType::from($m[1]));
        }
        return new PicoType(BaseType::from($type));
    }

    /** @param int $size element count (0 = unknown/dynamic) */
    public static function array(BaseType $elementType, int $size = 0): PicoType
    {
        $pt = new PicoType(BaseType::PTR);
        $pt->isArray = true;
        $pt->elementType = $elementType;
        $pt->arraySize = $size;
        return $pt;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isArray(): bool
    {
        return $this->isArray;
    }

    public function getElementType(): BaseType
    {
        assert($this->elementType !== null, 'getElementType() called on non-array PicoType');
        return $this->elementType;
    }

    public function getArraySize(): int
    {
        return $this->arraySize;
    }

    public function setArraySize(int $size): void
    {
        $this->arraySize = $size;
    }

    public function toString(): string
    {
        return ($this->nullable ? '?' : '') . $this->type->value;
    }
}
