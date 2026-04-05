<?php

declare(strict_types=1);

use App\PicoHP\CompilerInvariant;
use App\PicoHP\CompilerInvariantException;

it('does nothing when the condition holds', function () {
    CompilerInvariant::check(true);
    expect(true)->toBeTrue();
});

it('throws CompilerInvariantException when the condition fails', function () {
    CompilerInvariant::check(false, 'unit test invariant');
})->throws(CompilerInvariantException::class);
