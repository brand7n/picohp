<?php

declare(strict_types=1);

it('rejects mismatched return type', function () {
    $file = 'tests/programs/functions/return_type_mismatch.php';

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}");
})->throws(\Exception::class, 'return type mismatch');
