<?php

declare(strict_types=1);


it('builds a picoHP program', function () {
    /** @phpstan-ignore-next-line */
    $this->artisan('build examples/example1.php')->assertExitCode(0);
});
