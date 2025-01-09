<?php

declare(strict_types=1);


it('builds a picoHP program', function () {
    /** @phpstan-ignore-next-line */
    $this->artisan('build test.php')->assertExitCode(0);
});
