<?php

declare(strict_types=1);

it('allows --entry for directory precompile plan', function () {
    $root = dirname(__DIR__, 2);
    ob_start();
    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build {$root} --precompile-plan --entry=picoHP");
    ob_get_clean();
});
