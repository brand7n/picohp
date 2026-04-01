<?php

declare(strict_types=1);

use App\PicoHP\Precompile\CompilationPlanner;

it('applies class path overrides when planning a directory build', function () {
    $root = dirname(__DIR__, 3).'/tests/fixtures/precompile_smoke';
    $entry = $root.'/src/main.php';
    $planner = new CompilationPlanner();
    $overridePath = $root.'/src/Greeter.php';
    $plan = $planner->planDirectoryBuild($root, $entry, [
        'T\\Override' => $overridePath,
    ]);
    expect($plan->classPathOverrides)->toBe([
        'T\\Override' => $overridePath,
    ]);
});
