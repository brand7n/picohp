<?php

declare(strict_types=1);

$env = static function (string $key, ?string $default = null): string {
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }

    return $default ?? '';
};

$gitVersion = trim((string) @shell_exec('git describe --tags --always 2>/dev/null'));

return [
    'name' => 'picoHP',
    'version' => $gitVersion !== '' ? $gitVersion : 'dev',
    'env' => 'development',
    'llvm_path' => $env('LLVM_PATH', '/usr/bin'),
    'build_path' => $env('BUILD_PATH', '/tmp/picoHP'),
    'runtime_path' => $env('RUNTIME_PATH', 'runtime/target/release'),
];
