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
