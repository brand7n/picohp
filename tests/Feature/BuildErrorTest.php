<?php

declare(strict_types=1);

it('fails gracefully when input file does not exist', function () {
    /** @phpstan-ignore-next-line */
    $this->artisan('build nonexistent_file.php')->assertExitCode(1);
});
