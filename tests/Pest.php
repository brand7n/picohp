<?php

declare(strict_types=1);

/**
 * Compile a JSON AST fixture to a native binary and return its stdout.
 * The oracle is the PHP interpreter on the matching program in tests/programs/.
 */
function compileAndRun(string $fixture, bool $optimize = false): string
{
    $root = dirname(__DIR__);
    $json = "{$root}/tests/Fixtures/{$fixture}.json";
    $ll = sys_get_temp_dir() . "/picoir_{$fixture}" . ($optimize ? '_opt' : '') . '.ll';
    $bin = sys_get_temp_dir() . "/picoir_{$fixture}" . ($optimize ? '_opt' : '') . '.bin';

    $opt = $optimize ? ' --optimize' : '';
    exec(PHP_BINARY . " {$root}/bin/picoir {$json}{$opt} > " . escapeshellarg($ll) . ' 2>&1', $o, $code);
    expect($code)->toBe(0, "picoir failed: " . implode("\n", $o));

    exec('clang ' . escapeshellarg($ll) . ' -o ' . escapeshellarg($bin) . ' 2>/dev/null', $o, $code);
    expect($code)->toBe(0, "clang failed: " . implode("\n", $o));

    $out = shell_exec(escapeshellarg($bin));
    return $out ?? '';
}

/** Run the PHP oracle on the matching program. */
function oracle(string $program): string
{
    $root = dirname(__DIR__);
    return shell_exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/tests/programs/{$program}.php")) ?? '';
}
