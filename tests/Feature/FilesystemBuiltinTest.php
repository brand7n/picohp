<?php

declare(strict_types=1);

it('compiles is_file and file_exists with runtime filesystem checks', function () {
    $file = 'tests/programs/files/is_file_exists.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});
