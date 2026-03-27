<?php

declare(strict_types=1);

use App\Commands\BuildCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('prints assignment type mismatch with source line and compiler call site', function () {
    $file = 'tests/programs/semantic/assignment_type_mismatch.php';

    $application = new Application();
    $application->setAutoExit(false);
    $application->addCommand(new BuildCommand());
    $output = new BufferedOutput();
    $exitCode = $application->run(new StringInput('build ' . $file), $output);

    expect($exitCode)->toBe(1);
    expect($output->fetch())->toMatch(
        '/line 6, type mismatch in assignment: int = string \(at\s+app\/PicoHP\/Pass\/SemanticAnalysisPass\.php:\d+\)/'
    );
});

it('prints absolute file path for invariants triggered outside project root', function () {
    $projectRoot = dirname(__DIR__, 2);
    $tmpFile = tempnam(sys_get_temp_dir(), 'picohp_inv_');
    \App\PicoHP\CompilerInvariant::check(is_string($tmpFile));

    $autoloadPath = $projectRoot . '/vendor/autoload.php';
    $autoloadPathLiteral = var_export($autoloadPath, true);
    $code = "<?php\nrequire {$autoloadPathLiteral};\n\n\\App\\PicoHP\\CompilerInvariant::check(false, 'outside-project-root');\n";

    file_put_contents($tmpFile, $code);

    try {
        $cmd = 'php ' . escapeshellarg($tmpFile) . ' 2>&1';
        exec($cmd, $lines, $exitCode);
        $output = implode("\n", $lines);

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('outside-project-root');
        expect($output)->toContain(basename($tmpFile));
    } finally {
        @unlink($tmpFile);
    }
});
