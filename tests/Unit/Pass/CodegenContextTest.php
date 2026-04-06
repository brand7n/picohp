<?php

declare(strict_types=1);

use App\PicoHP\Pass\CodegenContext;

it('saves and restores context', function () {
    $ctx = new CodegenContext();
    $ctx->className = 'Foo';

    $saved = $ctx->save();
    $ctx->className = 'Bar';

    expect($ctx->className)->toBe('Bar');
    $ctx->restore($saved);
    expect($ctx->className)->toBe('Foo');
});

it('enters and leaves class', function () {
    $ctx = new CodegenContext();
    $ctx->enterClass('MyClass');
    expect($ctx->className)->toBe('MyClass');
    expect($ctx->thisPtr)->toBeNull();

    $ctx->leaveClass();
    expect($ctx->className)->toBeNull();
});
