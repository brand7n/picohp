<?php

declare(strict_types=1);

$programs = [
    'less than or equal' => 'tests/programs/comparison/less_equal.php',
    'greater than or equal' => 'tests/programs/comparison/greater_equal.php',
    'not equal' => 'tests/programs/comparison/not_equal.php',
    'logical and' => 'tests/programs/comparison/logical_and.php',
    'logical or' => 'tests/programs/comparison/logical_or.php',
    'combined comparison and logic' => 'tests/programs/comparison/combined.php',
    'cross-type identity comparison' => 'tests/programs/comparison/cross_type_identity.php',
];

foreach ($programs as $name => $file) {
    it("compiles {$name} operators correctly", function () use ($file) {
        /** @phpstan-ignore-next-line */
        $this->assertPicohpExitCode("build --debug {$file}");

        $buildPath = config('app.build_path');
        assert(is_string($buildPath));
        $compiled_output = shell_exec("{$buildPath}/a.out");
        $php_output = shell_exec("php {$file}");

        expect($compiled_output)->toBe($php_output);
    });
}
