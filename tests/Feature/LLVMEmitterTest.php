<?php

declare(strict_types=1);

use App\PicoHP\LLVM\FunctionEmitter;

it('can emit LLVM IR', function () {
    $fnEmitter = new FunctionEmitter("main", []);
    $fnEmitter->begin();
    $fnEmitter->end();

    $result = 0;
    exec('clang out.ll -o test', result_code: $result);
    expect($result)->toBe(0);
})->onlyOnMac(); // stop this from running under github actions for now
