<?php

declare(strict_types=1);

it('echoes string variables correctly', function () {
    $file = 'tests/programs/echo/echo_string_var.php';

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});

it('passes and returns strings from functions', function () {
    $file = 'tests/programs/functions/string_param.php';

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});
