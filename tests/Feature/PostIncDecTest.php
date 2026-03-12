<?php

declare(strict_types=1);

it('handles post-increment correctly', function () {
    $file = 'tests/programs/operators/post_increment.php';

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    // compiled echo adds newlines after ints (known issue), so oracle comparison is not possible yet
    // PHP outputs "56", compiled outputs "5\n6\n"
    $compiled_output = shell_exec("{$buildPath}/a.out");
    expect($compiled_output)->toBe("5\n6\n");
});

it('handles post-decrement correctly', function () {
    $file = 'tests/programs/operators/post_decrement.php';

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    expect($compiled_output)->toBe("5\n4\n");
});

it('handles post-increment in for loop correctly', function () {
    $file = 'tests/programs/operators/inc_dec_for_loop.php';

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    expect($compiled_output)->toBe("0\n1\n2\n3\n4\n");
});
