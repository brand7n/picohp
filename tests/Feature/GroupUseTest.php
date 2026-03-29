<?php

declare(strict_types=1);

it('accepts Stmt_GroupUse in namespace block', function () {
    $file = 'tests/programs/namespaces/group_use.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}", 0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});
