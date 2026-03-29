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

it('strips the project root prefix for relative paths', function () {
    $method = new ReflectionMethod(CompilerInvariant::class, 'relativeToProjectRoot');
    $root = dirname(__DIR__, 2);
    $inside = realpath($root . '/app/config.php');
    expect($inside)->not->toBeFalse();
    expect($method->invoke(null, $inside))->toBe('app/config.php');
});

it('keeps absolute paths outside the project', function () {
    $method = new ReflectionMethod(CompilerInvariant::class, 'relativeToProjectRoot');
    expect($method->invoke(null, '/tmp/picohp_nonexistent.php'))->toBe('/tmp/picohp_nonexistent.php');
});
