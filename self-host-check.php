#!/usr/bin/env php
<?php

/**
 * Self-hosting status checker for picoHP.
 *
 * Attempts to compile each PHP source file in the compiler codebase
 * and nikic/php-parser, reporting which files succeed and which fail.
 * Also tests multi-file compilation of related file groups.
 *
 * Usage: php self-host-check.php [--verbose] [--multi]
 */

$verbose = in_array('--verbose', $argv, true);
$multiOnly = in_array('--multi', $argv, true);
$picoHP = __DIR__ . '/picoHP';
$buildPath = __DIR__ . '/build';
$tmpDir = sys_get_temp_dir() . '/picohp-multi-test';

if (!$multiOnly) {
    singleFileCheck($picoHP, $verbose);
}

multiFileCheck($picoHP, $tmpDir, $verbose);

function singleFileCheck(string $picoHP, bool $verbose): void
{
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

        echo "\n━━━ {$label} (single-file) ━━━\n";

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

            $output = [];
            $exitCode = 0;
            exec("{$picoHP} build {$path} 2>&1", $output, $exitCode);

            if ($exitCode === 0) {
                $pass++;
                $results[] = ['status' => 'pass', 'file' => $relative, 'error' => ''];
            } else {
                $fail++;
                $errorMsg = extractError($output);
                $results[] = ['status' => 'fail', 'file' => $relative, 'error' => $errorMsg];
                $errors[$errorMsg] = ($errors[$errorMsg] ?? 0) + 1;
            }
        }

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

    echo "\n━━━ Single-file summary ━━━\n";
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
}

function multiFileCheck(string $picoHP, string $tmpDir, bool $verbose): void
{
    echo "\n━━━ Multi-file groups ━━━\n";

    @mkdir($tmpDir, 0755, true);

    $groups = [
        'LLVM Value hierarchy' => [
            __DIR__ . '/app/PicoHP/LLVM/ValueAbstract.php',
            __DIR__ . '/app/PicoHP/LLVM/Value/Void_.php',
            __DIR__ . '/app/PicoHP/LLVM/Value/NullConstant.php',
            __DIR__ . '/app/PicoHP/LLVM/Value/Param.php',
            __DIR__ . '/app/PicoHP/LLVM/Value/Global_.php',
            __DIR__ . '/app/PicoHP/LLVM/Value/Instruction.php',
            __DIR__ . '/app/PicoHP/LLVM/Value/AllocaInst.php',
            __DIR__ . '/app/PicoHP/LLVM/Value/Label.php',
            __DIR__ . '/app/PicoHP/LLVM/Value/Constant.php',
        ],
        'LLVM IR infrastructure' => [
            __DIR__ . '/app/PicoHP/LLVM/IRLine.php',
            __DIR__ . '/app/PicoHP/LLVM/BasicBlock.php',
        ],
        'Symbol table' => [
            __DIR__ . '/app/PicoHP/SymbolTable/Symbol.php',
            __DIR__ . '/app/PicoHP/SymbolTable/Scope.php',
            __DIR__ . '/app/PicoHP/SymbolTable.php',
        ],
        'php-parser Node base' => [
            __DIR__ . '/vendor/nikic/php-parser/lib/PhpParser/NodeAbstract.php',
            __DIR__ . '/vendor/nikic/php-parser/lib/PhpParser/Comment.php',
        ],
        'picoHP ClassMetadata + Symbol' => [
            __DIR__ . '/app/PicoHP/SymbolTable/Symbol.php',
            __DIR__ . '/app/PicoHP/SymbolTable/ClassMetadata.php',
        ],
        'picoHP PassInterface + IRLine' => [
            __DIR__ . '/app/PicoHP/PassInterface.php',
            __DIR__ . '/app/PicoHP/LLVM/IRLine.php',
        ],
    ];

    $groupPass = 0;
    $groupFail = 0;

    foreach ($groups as $name => $files) {
        // Verify all files exist
        $missing = array_filter($files, fn ($f) => !file_exists($f));
        if (count($missing) > 0) {
            echo "  ⚠ {$name}: missing files\n";
            continue;
        }

        // Concatenate files, stripping duplicate PHP tags and namespace/use statements
        $combined = "<?php\n\n";
        foreach ($files as $file) {
            $content = file_get_contents($file);
            assert(is_string($content));
            // Strip PHP open tags
            $content = preg_replace('/^<\?php\s*/', '', $content);
            assert(is_string($content));
            // Strip namespace declarations
            $content = preg_replace('/^namespace\s+[^;]+;\s*$/m', '', $content);
            // Strip use statements
            $content = preg_replace('/^use\s+[^;]+;\s*$/m', '', $content);
            // Strip declare statements
            $content = preg_replace('/^declare\s*\([^)]+\)\s*;\s*$/m', '', $content);
            assert(is_string($content));
            $combined .= "// --- " . basename($file) . " ---\n";
            $combined .= $content . "\n";
        }

        $combinedPath = "{$tmpDir}/" . str_replace(' ', '_', $name) . '.php';
        file_put_contents($combinedPath, $combined);

        $output = [];
        $exitCode = 0;
        exec("{$picoHP} build {$combinedPath} 2>&1", $output, $exitCode);

        $fileCount = count($files);
        if ($exitCode === 0) {
            $groupPass++;
            echo "  ✅ {$name} ({$fileCount} files)\n";
        } else {
            $groupFail++;
            $errorMsg = extractError($output);
            echo "  ❌ {$name} ({$fileCount} files)\n";
            if ($verbose) {
                echo "     {$errorMsg}\n";
            }
        }
    }

    echo "\n━━━ Multi-file summary ━━━\n";
    $groupTotal = $groupPass + $groupFail;
    echo "  Groups: {$groupTotal}  Pass: {$groupPass}  Fail: {$groupFail}\n";
}

/**
 * @param array<string> $output
 */
function extractError(array $output): string
{
    $errorMsg = '';
    foreach ($output as $line) {
        $line = trim($line);
        if ($line !== '' && !str_starts_with($line, '#') && !str_starts_with($line, 'at ')) {
            $errorMsg = $line;
            break;
        }
    }
    if (preg_match('/unknown node type.*: (.+)/', $errorMsg, $m)) {
        $errorMsg = 'unsupported: ' . basename($m[1]);
    } elseif (preg_match('/function (.+) not found/', $errorMsg, $m)) {
        $errorMsg = 'missing function: ' . $m[1];
    } elseif (preg_match('/assert\((.+)\)/', $errorMsg, $m)) {
        $errorMsg = 'assert: ' . substr($m[1], 0, 60);
    }
    return $errorMsg;
}
