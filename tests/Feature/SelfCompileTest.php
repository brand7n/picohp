<?php

declare(strict_types=1);

it('self-compiles IRLine class', function () {
    $file = 'tests/programs/self_compile/irline_test.php';

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});

it('self-compiles Symbol class', function () {
    $file = 'tests/programs/self_compile/symbol_test.php';

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});

it('compiles parser table lookup and calls it via FFI', function () {
    $file = 'tests/programs/self_compile/parser_ffi_test.php';

    // Oracle test: compiled output matches PHP
    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");
    expect($compiled_output)->toBe($php_output);

    // FFI test: build as shared lib and call from PHP
    /** @phpstan-ignore-next-line */
    $this->artisan("build {$file} --shared-lib --out=parser_ffi_test.so")->assertExitCode(0);

    $ffi = \FFI::cdef("int parser_ffi_test();", "{$buildPath}/parser_ffi_test.so");

    /** @phpstan-ignore-next-line */
    expect($ffi->parser_ffi_test())->toBe(450);
});
