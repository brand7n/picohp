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
        return match ($this) {
            BaseType::INT => 'i32',
            BaseType::FLOAT => 'double',
            BaseType::BOOL => 'i1',
            BaseType::VOID => 'void',
            BaseType::STRING, BaseType::PTR => 'ptr',
            default => 'i8*',
        };
    }
}
