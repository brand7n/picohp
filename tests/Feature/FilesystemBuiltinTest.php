<?php

declare(strict_types=1);

it('handles is_file, is_dir, file_exists, and file_get_contents', function () {
    $file = 'tests/programs/files/filesystem_builtins.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});
