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
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        $frame = $bt[0] ?? [];
        $fileRaw = $frame['file'] ?? null;
        $file = is_string($fileRaw) ? $fileRaw : 'unknown';
        $line = $frame['line'] ?? 0;

        $where = self::relativeToProjectRoot($file) . ':' . $line;
        throw new CompilerInvariantException("{$message} (at {$where})");
    }

    private static function relativeToProjectRoot(string $absolutePath): string
    {
        $root = dirname(__DIR__, 2);
        $realRoot = realpath($root);
        if ($realRoot !== false) {
            $root = $realRoot;
        }
        $prefix = $root . DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $prefix)) {
            return substr($absolutePath, strlen($prefix));
        }

        return $absolutePath;
    }
}
