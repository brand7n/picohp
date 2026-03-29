<?php

declare(strict_types=1);

it('rejects mismatched return type', function () {
    $file = 'tests/programs/functions/return_type_mismatch.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}", 1);
});
