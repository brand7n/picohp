<?php

declare(strict_types=1);

$fixturePrecompileRoot = dirname(__DIR__) . '/fixtures/precompile_smoke';

/**
 * Canonical small-but-non-trivial compile smoke: classes, nested loops, str_contains, static call.
 */
it('compiles the smoke word scanner program and matches PHP output', function () {
    $file = 'tests/programs/smoke/word_scan_smoke.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});

it('runs the precompile planner on a minimal Composer project fixture', function () use ($fixturePrecompileRoot) {
    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build {$fixturePrecompileRoot} --precompile-plan --entry=src/main.php");
});

it('compiles the minimal Composer project fixture as a directory build', function () use ($fixturePrecompileRoot) {
    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build {$fixturePrecompileRoot} --entry=src/main.php");
});
