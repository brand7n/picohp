<?php

declare(strict_types=1);

it('compiles function declarations and calls', function () {
    expect(compileAndRun('functions'))->toBe(oracle('functions'));
});

it('compiles control flow (while, if/else, for)', function () {
    expect(compileAndRun('control_flow'))->toBe(oracle('control_flow'));
});

it('compiles echo with strings and ints', function () {
    expect(compileAndRun('strings'))->toContain('hello pico');
});

it('compiles classes, promoted properties and enums', function () {
    expect(compileAndRun('objects'))->toBe(oracle('objects'));
});

it('compiles classes, promoted properties and enums with optimization passes', function () {
    expect(compileAndRun('objects', optimize: true))->toBe(oracle('objects'));
});

it('compiles function declarations and calls with optimization passes', function () {
    expect(compileAndRun('functions', optimize: true))->toBe(oracle('functions'));
});

it('compiles control flow with optimization passes', function () {
    expect(compileAndRun('control_flow', optimize: true))->toBe(oracle('control_flow'));
});
