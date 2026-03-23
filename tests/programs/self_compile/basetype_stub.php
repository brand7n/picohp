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

/**
 * Stub for the built-in backed enum method BaseType::tryFrom().
 * ClassToFunctionVisitor transforms BaseType::tryFrom($x) into BaseType_tryFrom($x).
 */
function BaseType_tryFrom(string $value): ?BaseType
{
    if ($value === 'int') {
        return BaseType::INT;
    }
    if ($value === 'float') {
        return BaseType::FLOAT;
    }
    if ($value === 'bool') {
        return BaseType::BOOL;
    }
    if ($value === 'string') {
        return BaseType::STRING;
    }
    if ($value === 'void') {
        return BaseType::VOID;
    }
    if ($value === 'ptr') {
        return BaseType::PTR;
    }
    return null;
}
