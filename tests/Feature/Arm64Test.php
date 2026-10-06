<?php

declare(strict_types=1);

it('emits ARM64 assembly for an arithmetic program', function () {
    $root = dirname(__DIR__, 2);
    $json = "{$root}/tests/Fixtures/arm64_arith.json";
    $asm = shell_exec(PHP_BINARY . " {$root}/bin/picoir {$json} --emit-arm64 2>&1");
    expect($asm)->toBeString()->toContain('_main:')->toContain('ret');
})->skipOnWindows();
