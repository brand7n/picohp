<?php

declare(strict_types=1);


it('calls a picoHP lib from PHP', function () {
    /** @phpstan-ignore-next-line */
    $this->artisan('build examples/php_ception.php --shared-lib --out=ffitest.so')->assertExitCode(0);

    $ffi = FFI::cdef("int ffitest();", "./build/ffitest.so");
    /** @phpstan-ignore-next-line */
    expect($ffi->ffitest())->toBe(49);
});
