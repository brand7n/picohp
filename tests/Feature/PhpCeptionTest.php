<?php

declare(strict_types=1);


it('calls a picoHP lib from PHP', function () {
    /** @phpstan-ignore-next-line */
    $this->artisan('build examples/php_ception.php --shared-lib --out=ffitest.so')->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));

    $ffi = FFI::cdef("int ffitest();", "{$buildPath}/ffitest.so");

    /** @phpstan-ignore-next-line */
    expect($ffi->ffitest())->toBe(49);
});
