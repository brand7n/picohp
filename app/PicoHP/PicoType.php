<?php

declare(strict_types=1);

namespace App\PicoHP;

enum BaseType: string
{
    case INT = 'int';
    case FLOAT = 'float';
    case DOUBLE = 'double';
    case BOOL = 'bool';
    case STRING = 'string';
    case VOID = 'void';
    case PTR = 'ptr';
    case LABEL = 'label';

    public function toLLVM(): string
    {
        return match($this) {
            BaseType::INT => 'i32',
            BaseType::FLOAT => 'float',
            BaseType::DOUBLE => 'double',
            BaseType::BOOL => 'i1',
            BaseType::VOID => 'void',
            BaseType::STRING => "[256 x i8]",
            default => 'i8*',
        };
    }

    public function toQBE(): string
    {
        return match($this) {
            BaseType::INT => 'w',
            BaseType::FLOAT => 's',
            BaseType::DOUBLE => 'd',
            BaseType::BOOL => 'w',
            BaseType::VOID => 'v',
            default => 'l',
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
        return new PicoType(BaseType::from($type));
    }

    public function toString(): string
    {
        return $this->type->value;
    }
}
