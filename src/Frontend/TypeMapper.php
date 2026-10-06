<?php

declare(strict_types=1);

namespace PicoIR\Frontend;

use PicoIR\BaseType;

/**
 * Maps PHPStan/PHP type strings ("int", "non-empty-string", "int|null") to PicoIR BaseType.
 */
final class TypeMapper
{
    public static function map(?string $phpType): BaseType
    {
        if ($phpType === null) {
            return BaseType::INT; // untyped params/vars default to int (i32) for now
        }
        $t = strtolower(trim($phpType));
        // strip nullability: "int|null" → int (ponytail: nullable support later)
        $t = explode('|', $t)[0];
        $t = trim($t);
        return match (true) {
            str_contains($t, 'int') => BaseType::INT,
            str_contains($t, 'float'), $t === 'double' => BaseType::FLOAT,
            str_contains($t, 'string') => BaseType::STRING,
            str_contains($t, 'bool') => BaseType::BOOL,
            $t === 'void', $t === 'null', $t === 'never' => BaseType::VOID,
            default => BaseType::PTR,
        };
    }
}
