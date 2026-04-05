<?php

declare(strict_types=1);

use App\PicoHP\Precompile\ComposerAutoloadGraph;
use App\PicoHP\Precompile\ReachabilityAnalyzer;

it('follows literal require and classmap edges', function () {
    $dir = sys_get_temp_dir() . '/picohp_reach_' . uniqid('', true);
    mkdir($dir, 0700, true);
    $main = $dir . '/main.php';
    $a = $dir . '/a.php';
    try {
        file_put_contents($main, "<?php\n\ndeclare(strict_types=1);\n\nrequire 'a.php';\n");
        file_put_contents($a, "<?php\n\ndeclare(strict_types=1);\n\nnamespace T;\n\nfinal class Cl\n{\n}\n");

        $mainReal = realpath($main);
        $aReal = realpath($a);
        if ($mainReal === false || $aReal === false) {
            throw new \RuntimeException('realpath failed for temp precompile test files');
        }

        $graph = new ComposerAutoloadGraph(classmap: ['T\\Cl' => $aReal]);
        $analyzer = ReachabilityAnalyzer::createDefault();
        $result = $analyzer->analyze($graph, [$mainReal], $dir);

        expect($result->reachableFiles)->toContain($mainReal);
        expect($result->reachableFiles)->toContain($aReal);
    } finally {
        @unlink($main);
        @unlink($a);
        @rmdir($dir);
    }
});

it('follows classmap into project vendor/ when referenced by FQCN', function () {
    $dir = sys_get_temp_dir() . '/picohp_reach_vendor_' . uniqid('', true);
    mkdir($dir, 0700, true);
    mkdir($dir . '/vendor/pkg', 0700, true);
    $main = $dir . '/main.php';
    $vendorClass = $dir . '/vendor/pkg/Dep.php';
    try {
        file_put_contents($main, "<?php\nnew \\T\\Dep();\n");
        file_put_contents($vendorClass, "<?php\nnamespace T;\nclass Dep {}\n");

        $mainReal = realpath($main);
        $vendorReal = realpath($vendorClass);
        if ($mainReal === false || $vendorReal === false) {
            throw new \RuntimeException('realpath failed for temp precompile test files');
        }

        $graph = new ComposerAutoloadGraph(classmap: ['T\\Dep' => $vendorReal]);
        $result = ReachabilityAnalyzer::createDefault()->analyze($graph, [$mainReal], $dir);

        expect($result->reachableFiles)->toContain($mainReal);
        expect($result->reachableFiles)->toContain($vendorReal);
    } finally {
        @unlink($main);
        @unlink($vendorClass);
        @rmdir($dir . '/vendor/pkg');
        @rmdir($dir . '/vendor');
        @rmdir($dir);
    }
});

it('prefers classPathOverrides over Composer classmap for the same FQCN', function () {
    $dir = sys_get_temp_dir() . '/picohp_reach_override_' . uniqid('', true);
    mkdir($dir, 0700, true);
    $main = $dir . '/main.php';
    $vendorClass = $dir . '/vendor.php';
    $overrideClass = $dir . '/override.php';
    try {
        file_put_contents($main, "<?php\nnew \\T\\X();\n");
        file_put_contents($vendorClass, "<?php\nnamespace T;\nclass X {}\n");
        file_put_contents($overrideClass, "<?php\nnamespace T;\nclass X {}\n");

        $mainReal = realpath($main);
        $overrideReal = realpath($overrideClass);
        $vendorReal = realpath($vendorClass);
        if ($mainReal === false || $overrideReal === false || $vendorReal === false) {
            throw new \RuntimeException('realpath failed for temp precompile test files');
        }

        $graph = new ComposerAutoloadGraph(
            classmap: ['T\\X' => $vendorReal],
            classPathOverrides: ['T\\X' => $overrideReal],
        );
        $result = ReachabilityAnalyzer::createDefault()->analyze($graph, [$mainReal], $dir);

        expect($result->reachableFiles)->toContain($overrideReal);
        expect($result->reachableFiles)->not->toContain($vendorReal);
    } finally {
        @unlink($main);
        @unlink($vendorClass);
        @unlink($overrideClass);
        @rmdir($dir);
    }
});

it('follows classmap from a new expression without require', function () {
    $dir = sys_get_temp_dir() . '/picohp_reach2_' . uniqid('', true);
    mkdir($dir, 0700, true);
    $main = $dir . '/main.php';
    $a = $dir . '/a.php';
    try {
        file_put_contents($main, "<?php\nnew \\T\\Cl();\n");
        file_put_contents($a, "<?php\nnamespace T;\nclass Cl {}\n");

        $mainReal = realpath($main);
        $aReal = realpath($a);
        if ($mainReal === false || $aReal === false) {
            throw new \RuntimeException('realpath failed for temp precompile test files');
        }

        $graph = new ComposerAutoloadGraph(classmap: ['T\\Cl' => $aReal]);
        $result = ReachabilityAnalyzer::createDefault()->analyze($graph, [$mainReal], $dir);

        expect($result->reachableFiles)->toContain($mainReal);
        expect($result->reachableFiles)->toContain($aReal);
    } finally {
        @unlink($main);
        @unlink($a);
        @rmdir($dir);
    }
});
