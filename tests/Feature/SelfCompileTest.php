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

it('self-compiles Php8 transformed parser and matches PHP oracle', function () {
    $file = 'tests/programs/self_compile/php8_transformed.php';

    if (!file_exists($file)) {
        // php8_transformed.php is generated and gitignored; CI needs to produce it.
        $generator = 'tests/programs/self_compile/generate_php8_stub.php';
        shell_exec("php {$generator}");
        expect(file_exists($file))->toBeTrue();
    }

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));

    $compiled_output = shell_exec("{$buildPath}/a.out");

    $src = file_get_contents($file);
    \App\PicoHP\CompilerInvariant::check(is_string($src), 'Failed to read Php8 transformed source');

    // The transformed Php8 parser defines RuntimeException/Error in the global namespace.
    // When running under stock PHP, that can collide with PHP's built-in names.
    // We run a namespaced oracle copy to avoid redeclare conflicts.
    $oracleSrc = preg_replace(
        '/declare\\(strict_types=1\\);\\s*/',
        "declare(strict_types=1);\n\nnamespace Php8Oracle;\n\n",
        $src,
        1,
    );
    \App\PicoHP\CompilerInvariant::check(is_string($oracleSrc));

    // Ensure any unqualified Exception references bind to global \Exception.
    $oracleSrc = preg_replace('/\\bException\\b/', '\\\\Exception', $oracleSrc);
    \App\PicoHP\CompilerInvariant::check(is_string($oracleSrc));

    $tmpOracle = tempnam(sys_get_temp_dir(), 'php8_oracle_');
    \App\PicoHP\CompilerInvariant::check($tmpOracle !== false);
    file_put_contents($tmpOracle, $oracleSrc);

    try {
        $php_output = shell_exec('php ' . escapeshellarg($tmpOracle));
        expect($compiled_output)->toBe($php_output);
    } finally {
        @unlink($tmpOracle);
    }

    // FFI test: build as shared lib and call real parser table lookups
    /** @phpstan-ignore-next-line */
    $this->artisan("build {$file} --shared-lib --out=php8_parser.so")->assertExitCode(0);

    $ffi = \FFI::cdef("int php8_parser_test();", "{$buildPath}/php8_parser.so");

    /** @phpstan-ignore-next-line */
    $ffiResult = (int) $ffi->php8_parser_test();
    // Should match the standalone tables FFI test (same tables, same algorithm)
    expect($ffiResult)->toBe(570086);
});
