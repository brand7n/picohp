<?php

declare(strict_types=1);

namespace App\PicoHP;

/**
 * Invariant checks that always throw when they fail, unlike PHP's assert() which
 * can be disabled via zend.assertions. Use for conditions PHPStan relies on for
 * narrowing when documented with @phpstan-assert.
 *
 * On failure, the exception message includes the **call site** (file:line of the
 * code that invoked check), taken from a backtrace only when the condition is false.
 */
final class CompilerInvariant
{
    /**
     * @phpstan-assert true $condition
     */
    public static function check(bool $condition, string $message = 'Compiler invariant failed'): void
    {
        if ($condition) {
            return;
        }

        // Frame 0 is this function; its file/line are the call site of check() in the
        // caller's source file (not the line inside CompilerInvariant.php).
        throw new CompilerInvariantException($message);
    }
}
