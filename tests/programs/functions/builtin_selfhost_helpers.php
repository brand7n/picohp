<?php

declare(strict_types=1);

/**
 * Exercises dirname, getenv, and ord for coverage of self-compile-related builtins.
 * of self-compile-related builtin lowering.
 */
/** @return void */
function run_selfhost_helper_builtins(): void
{
    echo \strval(\ord('X')) . "\n";
    $home = \getenv('HOME');
    echo ($home !== false ? \substr($home, 0, 1) : '?') . "\n";
    echo \dirname(__DIR__, 1) . "\n";
}

run_selfhost_helper_builtins();
