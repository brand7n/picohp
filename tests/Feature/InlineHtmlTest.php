<?php

declare(strict_types=1);

it('handles inline HTML correctly', function () {
    $file = 'tests/programs/control_flow/inline_html.php';

    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);
});
