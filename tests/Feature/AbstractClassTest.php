<?php

declare(strict_types=1);

it('handles abstract classes with concrete subclasses', function () {
    $file = 'tests/programs/classes/abstract_class.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});

it('handles abstract intermediate classes (Node → Expr → Concrete)', function () {
    $file = 'tests/programs/classes/abstract_intermediate.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});

it('handles abstract method dispatch and property access through abstract type', function () {
    $file = 'tests/programs/classes/abstract_property_access.php';

    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});
