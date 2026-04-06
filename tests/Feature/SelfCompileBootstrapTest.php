<?php

declare(strict_types=1);

it('self-compiles picoHP to a linked binary', function () {
    if (ini_get('memory_limit') !== '-1' && (int) ini_get('memory_limit') < 1024) {
        $this->markTestSkipped('Self-compile requires memory_limit >= 1G (run with: php -d memory_limit=1G)');
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
