<?php

declare(strict_types=1);

it('handles exit() with code 0', function () {
    $file = 'tests/programs/control_flow/exit_with_code.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));

    $output = [];
    $exitCode = 0;
    exec("{$buildPath}/a.out 2>&1", $output, $exitCode);
    $compiled_output = implode("\n", $output);

    $phpOutput = [];
    $phpExitCode = 0;
    exec("php {$file} 2>&1", $phpOutput, $phpExitCode);
    $php_output = implode("\n", $phpOutput);

    expect($compiled_output)->toBe($php_output);
    expect($exitCode)->toBe($phpExitCode);
});
