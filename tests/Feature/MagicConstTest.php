<?php

declare(strict_types=1);

it('compiles __DIR__ as the source file directory string', function () {
    $file = 'tests/programs/scalars/dir_magic.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}", 0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});
