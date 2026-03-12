<?php

declare(strict_types=1);

it('supports @var declarations in for-loop init clause', function () {
    $file = 'tests/programs/control_flow/for_loop_var_init.php';

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});
