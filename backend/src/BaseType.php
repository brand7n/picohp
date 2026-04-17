<?php

declare(strict_types=1);

namespace PicoIR;

enum BaseType: string
{
    case INT = 'int';
    case FLOAT = 'float';
    case BOOL = 'bool';
    case STRING = 'string';
    case PTR = 'ptr';
    case VOID = 'void';
    case LABEL = 'label';

    public function toLLVM(): string
    {
        return match ($this) {
            self::INT => 'i32',
            self::FLOAT => 'double',
            self::BOOL => 'i1',
            self::STRING, self::PTR => 'ptr',
            self::VOID => 'void',
            self::LABEL => 'label',
        };
    }
}
