<?php

declare(strict_types=1);

/**
 * picoHP compiler intrinsics — stubs for running under regular PHP.
 *
 * Include this file in your PHP code to use picohp_debug() without errors
 * when running under Zend PHP. When compiled by picoHP, these functions are
 * replaced with compiler-generated implementations.
 *
 * Usage: require_once __DIR__ . '/vendor/path/to/picohp_stubs.php';
 *   or:  require_once 'picohp_stubs.php';
 */

if (!function_exists('picohp_debug')) {
    /**
     * Dump compile-time type info and runtime value.
     *
     * Under picoHP: prints [picohp_debug] $var type=<PicoType> ir=<LLVM type> val=<value>
     * Under Zend PHP: prints [picohp_debug] val=<value> (runtime only, no type info)
     */
    function picohp_debug(mixed $value): void
    {
        $type = get_debug_type($value);
        if (is_bool($value)) {
            $valStr = $value ? '1' : '0';
        } elseif (is_null($value)) {
            $valStr = 'null';
        } else {
            $valStr = (string) $value;
        }
        echo "[picohp_debug] type={$type} val={$valStr}\n";
    }
}
