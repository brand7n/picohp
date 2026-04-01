<?php

declare(strict_types=1);

use App\PicoHP\PicoType;

it('builds empty string-keyed superglobal map type', function () {
    $t = PicoType::serverSuperglobalEmptyArray();
    expect($t->isArray())->toBeTrue();
    expect($t->hasStringKeys())->toBeTrue();
});
