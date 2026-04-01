<?php

declare(strict_types=1);

/**
 * Runs the compile pipeline on nearly every tests/programs fixture to lift Semantic + IR line coverage.
 * Skips generators, huge generated files, intentional type-mismatch tests, and sources that need BuildCommand file context for __DIR__.
 *
 * @return list<string>
 */
function picohpFixturePipelinePhpFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = glob($root.'/programs/**/*.php') ?: [];
    $skipBasenames = [
        'php8_transformed.php',
        'generate_parser_ffi.php',
        'generate_php8_stub.php',
        'builtin_selfhost_helpers.php',
        'dir_magic.php',
        'return_type_mismatch.php',
        'assignment_type_mismatch.php',
    ];
    $out = [];
    foreach ($files as $f) {
        if (in_array(basename($f), $skipBasenames, true)) {
            continue;
        }
        if (str_contains($f, '/generate_')) {
            continue;
        }
        $out[] = $f;
    }

    return $out;
}

foreach (picohpFixturePipelinePhpFiles() as $path) {
    $rel = str_replace(dirname(__DIR__, 2).DIRECTORY_SEPARATOR, '', $path);
    it('fixture pipeline: '.$rel, function () use ($path): void {
        $src = file_get_contents($path);
        expect($src)->not->toBe('');
        picohpRunMiniPipeline($src);
    });
}
