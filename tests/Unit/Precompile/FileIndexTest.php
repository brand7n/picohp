<?php

declare(strict_types=1);

use App\PicoHP\Precompile\FileIndex;

it('constructs with indexed declarations and references', function () {
    $idx = new FileIndex(
        filePath: '/proj/src/a.php',
        declaredClasses: ['A'],
        declaredFunctions: ['fa'],
        referencedClasses: ['B'],
        requiredFiles: ['/proj/b.php'],
    );
    expect($idx->filePath)->toBe('/proj/src/a.php');
    expect($idx->declaredClasses)->toBe(['A']);
    expect($idx->declaredFunctions)->toBe(['fa']);
    expect($idx->referencedClasses)->toBe(['B']);
    expect($idx->requiredFiles)->toBe(['/proj/b.php']);
});
