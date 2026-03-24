<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('prints assignment type mismatch with source line and compiler call site', function () {
    $file = 'tests/programs/semantic/assignment_type_mismatch.php';

    $exitCode = Artisan::call('build', ['filename' => $file]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toMatch(
        '/line 6, type mismatch in assignment: int = string \(at app\/PicoHP\/Pass\/SemanticAnalysisPass\.php:\d+\)/'
    );
});
