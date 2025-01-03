<?php

declare(strict_types=1);

use App\PicoHP\LLVM\Builder;

it('can emit LLVM IR', function () {
    $builder = new Builder("arm64-apple-macosx14.0.0", "e-m:o-i64:64-i128:128-n32:64-S128");
    $file = fopen("out.ll", "w");
    if ($file === false) {
        throw new \Exception("unable to open out.ll");
    }
    $builder->print($file);
    fclose($file);

    //$builder->print(STDOUT);

    $result = 0;
    exec('clang out.ll -o test', result_code: $result);
    expect($result)->toBe(0);
})->onlyOnMac(); // stop this from running under github actions for now
