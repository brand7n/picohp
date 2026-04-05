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
    /**
     * When true, {@see \App\PicoHP\HandLexer\TokenAdapter} uses PicoHP’s native {@see \App\PicoHP\HandLexer\Lexer}
     * instead of Zend {@see \PhpParser\Token::tokenize()} (for self-hosting / experiments).
     * Override with env {@code PICOHP_USE_NATIVE_LEXER=1}.
     */
    'use_native_lexer' => $env('PICOHP_USE_NATIVE_LEXER', '0') === '1',
];
