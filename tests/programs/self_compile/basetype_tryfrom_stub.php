<?php

declare(strict_types=1);

/**
 * Stub for BaseType::tryFrom(). Returns the enum tag (i32) or -1 if not found.
 * PicoType::fromString() checks the result with !== null, which with cross-type
 * comparison resolves at compile time to always true (int !== null).
 */
function BaseType_tryFrom(string $value): int
{
    if ($value === 'int') {
        return 0;
    }
    if ($value === 'float') {
        return 1;
    }
    if ($value === 'bool') {
        return 2;
    }
    if ($value === 'string') {
        return 3;
    }
    if ($value === 'void') {
        return 4;
    }
    if ($value === 'ptr') {
        return 5;
    }
    return -1;
}
