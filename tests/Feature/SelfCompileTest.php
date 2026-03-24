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

it('compiles real Php8 parser tables and calls lookups via FFI', function () {
    // Generate from real nikic/php-parser Php8 tables
    shell_exec('php tests/programs/self_compile/generate_parser_ffi.php');

    $file = 'tests/programs/self_compile/php8_tables_ffi.php';
    assert(file_exists($file), 'Generator failed to produce php8_tables_ffi.php');

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
    $this->artisan("build {$file} --shared-lib --out=php8_tables.so")->assertExitCode(0);

    $ffi = \FFI::cdef("int php8_table_test();", "{$buildPath}/php8_tables.so");

    /** @phpstan-ignore-next-line */
    $result = (int) $ffi->php8_table_test();
    /** @var int $expected */
    $expected = (int) shell_exec("php -r \"require '{$file}'; echo php8_table_test();\"");
    expect($result)->toBe($expected);
});
