<?php

declare(strict_types=1);

it('self-compiles picoHP to a linked binary', function () {
    if (getenv('PICOHP_SELF_COMPILE_TEST') === false) {
        $this->markTestSkipped('Set PICOHP_SELF_COMPILE_TEST=1 to run (requires 1G+ memory)');
    }
    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode(
        "build --debug --entry=picoHP --override-class 'PhpParser\\Token' compat/PhpParser/Token.php ."
    );

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $exe = "{$buildPath}/a.out";

    expect(file_exists($exe))->toBeTrue('self-compiled binary should exist');
    expect(is_executable($exe))->toBeTrue('self-compiled binary should be executable');

    // Run it with no args — should print usage and exit 1
    $output = [];
    $exitCode = 0;
    exec("{$exe} 2>&1", $output, $exitCode);
    $outputStr = implode("\n", $output);

    expect($outputStr)->toContain('Usage: picohp build');
});
