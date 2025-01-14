<?php

declare(strict_types=1);


it('builds a picoHP program', function () {
    /** @phpstan-ignore-next-line */
    $this->artisan('build examples/example1.php')->assertExitCode(0);

    /** @phpstan-ignore-next-line */
    $this->artisan('build examples/example2.php')->assertExitCode(0);
    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $exe = "{$buildPath}/a.out";
    exec($exe, result_code: $result);
    expect($result)->toBe(49);
});
