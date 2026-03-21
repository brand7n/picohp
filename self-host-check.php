#!/usr/bin/env php
<?php

/**
 * Self-hosting status checker for picoHP.
 *
 * Attempts to compile each PHP source file in the compiler codebase
 * and nikic/php-parser, reporting which files succeed and which fail.
 *
 * Usage: php self-host-check.php [--verbose]
 */

$verbose = in_array('--verbose', $argv, true);
$picoHP = __DIR__ . '/picoHP';
$buildPath = __DIR__ . '/build';

$dirs = [
    'picoHP compiler' => __DIR__ . '/app/PicoHP',
    'nikic/php-parser' => __DIR__ . '/vendor/nikic/php-parser/lib/PhpParser',
];

$pass = 0;
$fail = 0;
$total = 0;
$errors = [];

foreach ($dirs as $label => $dir) {
    if (!is_dir($dir)) {
        echo "⚠ Skipping {$label}: directory not found\n";
        continue;
    }

    echo "\n━━━ {$label} ━━━\n";

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $results = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $relative = str_replace(__DIR__ . '/', '', $path);
        $total++;

        // Try to compile (capture stderr)
        $output = [];
        $exitCode = 0;
        exec("{$picoHP} build {$path} 2>&1", $output, $exitCode);

        if ($exitCode === 0) {
            $pass++;
            $results[] = ['status' => 'pass', 'file' => $relative, 'error' => ''];
        } else {
            $fail++;
            // Extract first meaningful error line
            $errorMsg = '';
            foreach ($output as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, '#') && !str_starts_with($line, 'at ')) {
                    $errorMsg = $line;
                    break;
                }
            }
            // Shorten common error patterns
            if (preg_match('/unknown node type.*: (.+)/', $errorMsg, $m)) {
                $errorMsg = 'unsupported: ' . basename($m[1]);
            } elseif (preg_match('/function (.+) not found/', $errorMsg, $m)) {
                $errorMsg = 'missing function: ' . $m[1];
            } elseif (preg_match('/assert\((.+)\)/', $errorMsg, $m)) {
                $errorMsg = 'assert: ' . substr($m[1], 0, 60);
            }
            $results[] = ['status' => 'fail', 'file' => $relative, 'error' => $errorMsg];
            $errors[$errorMsg] = ($errors[$errorMsg] ?? 0) + 1;
        }
    }

    // Sort: passes first, then fails
    usort($results, fn ($a, $b) => $a['status'] <=> $b['status']);

    foreach ($results as $r) {
        if ($r['status'] === 'pass') {
            echo "  ✅ {$r['file']}\n";
        } else {
            if ($verbose) {
                echo "  ❌ {$r['file']}\n     {$r['error']}\n";
            } else {
                echo "  ❌ {$r['file']}\n";
            }
        }
    }
}

echo "\n━━━ Summary ━━━\n";
echo "  Total: {$total}  Pass: {$pass}  Fail: {$fail}\n";
$pct = $total > 0 ? round($pass / $total * 100, 1) : 0;
echo "  Success rate: {$pct}%\n";

if ($verbose && count($errors) > 0) {
    echo "\n━━━ Error frequency ━━━\n";
    arsort($errors);
    foreach (array_slice($errors, 0, 15) as $msg => $count) {
        echo "  {$count}x  {$msg}\n";
    }
}
