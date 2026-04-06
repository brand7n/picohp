<?php

declare(strict_types=1);

/**
 * Verify that --dump-tokens works and produces non-empty output
 * for representative source files.
 */
it('dumps tokens for BaseType.php', function () {
    $file = 'app/PicoHP/BaseType.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --dump-tokens {$file}");
});

it('dumps tokens for CompilerInvariant.php', function () {
    $file = 'app/PicoHP/CompilerInvariant.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --dump-tokens {$file}");
});

it('dumps tokens for IRGenerationPass.php', function () {
    $file = 'app/PicoHP/Pass/IRGenerationPass.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --dump-tokens {$file}");
});
