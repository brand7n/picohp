<?php

declare(strict_types=1);

it('reads LLVM_PATH from the environment when set', function () {
    $prev = getenv('LLVM_PATH');
    putenv('LLVM_PATH=/tmp/llvm-from-test');
    try {
        $config = require dirname(__DIR__, 2).'/app/config.php';
        expect($config['llvm_path'])->toBe('/tmp/llvm-from-test');
    } finally {
        if ($prev === false) {
            putenv('LLVM_PATH');
        } else {
            putenv('LLVM_PATH='.$prev);
        }
    }
});
