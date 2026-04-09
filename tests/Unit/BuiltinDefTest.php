<?php

declare(strict_types=1);

use App\PicoHP\BuiltinDef;
use App\PicoHP\PicoType;

it('reports param counts correctly', function () {
    $def = new BuiltinDef(
        name: 'substr',
        returnType: PicoType::fromString('string'),
        params: [
            ['name' => 'str', 'type' => PicoType::fromString('string'), 'hasDefault' => false, 'defaultValue' => null],
            ['name' => 'offset', 'type' => PicoType::fromString('int'), 'hasDefault' => false, 'defaultValue' => null],
            ['name' => 'length', 'type' => PicoType::fromString('int'), 'hasDefault' => true, 'defaultValue' => -1],
        ],
        runtimeSymbol: 'pico_string_substr',
        intrinsic: null,
        returnMatchesArg: null,
        returnElementType: null,
        requiredCount: 2,
    );

    expect($def->paramCount())->toBe(3);
    expect($def->requiredParamCount())->toBe(2);
    expect($def->returnBaseType()->value)->toBe('string');
});

it('handles intrinsic and return-matches-arg', function () {
    $def = new BuiltinDef(
        name: 'array_reverse',
        returnType: PicoType::fromString('array'),
        params: [
            ['name' => 'arr', 'type' => PicoType::fromString('array'), 'hasDefault' => false, 'defaultValue' => null],
        ],
        runtimeSymbol: null,
        intrinsic: 'identity',
        returnMatchesArg: 0,
        returnElementType: null,
    );

    expect($def->intrinsic)->toBe('identity');
    expect($def->returnMatchesArg)->toBe(0);
    expect($def->returnElementType)->toBeNull();
});
