<?php

declare(strict_types=1);

use App\PicoHP\Precompile\CompilationPlan;

it('exports a stable array shape from toArray', function () {
    $plan = new CompilationPlan(
        entrypoints: ['/e.php'],
        orderedFiles: ['/a.php', '/b.php'],
        classmapFiles: ['/c.php'],
        reachableFiles: ['/a.php'],
        prunedFiles: ['/z.php'],
        unresolvedClassReferences: ['X\\Y'],
        notes: ['n1'],
    );
    expect($plan->toArray())->toBe([
        'entrypoints' => ['/e.php'],
        'ordered_files' => ['/a.php', '/b.php'],
        'classmap_files' => ['/c.php'],
        'reachable_files' => ['/a.php'],
        'pruned_files' => ['/z.php'],
        'unresolved_class_references' => ['X\\Y'],
        'notes' => ['n1'],
    ]);
});
