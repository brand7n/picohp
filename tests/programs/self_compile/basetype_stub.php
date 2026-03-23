<?php

declare(strict_types=1);

enum BaseType: string
{
    case INT = 'int';
    case FLOAT = 'float';
    case BOOL = 'bool';
    case STRING = 'string';
    case VOID = 'void';
    case PTR = 'ptr';
    case LABEL = 'label';
}
