<?php

declare(strict_types=1);

it('allows --entry for directory precompile plan', function () {
    $root = dirname(__DIR__, 2);
    /** @phpstan-ignore-next-line */
    $this->artisan("build {$root} --precompile-plan --entry=picoHP")->assertExitCode(0);
});
