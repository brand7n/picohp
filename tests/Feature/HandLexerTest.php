<?php

declare(strict_types=1);

use App\PicoHP\HandLexer\Lexer;
use App\PicoHP\HandLexer\TokenType;

it('matches PHP oracle for minimal PicoHP lexer program', function () {
    $file = 'tests/programs/hand_lexer/minimal_picohp_lexer.php';
    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});

it('matches PHP oracle for HandLexer script in app/', function () {
    $file = 'app/PicoHP/HandLexer/src/main.php';
    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec('php -r \'require "app/PicoHP/HandLexer/LexerState.php"; require "app/PicoHP/HandLexer/TokenType.php"; require "app/PicoHP/HandLexer/Token.php"; require "app/PicoHP/HandLexer/Lexer.php"; $lexer = new \App\PicoHP\HandLexer\Lexer("<?php echo \\"hello world\\";"); $tokens = $lexer->tokenize(); echo count($tokens);\'');

    expect($compiled_output)->toBe($php_output);
})->skip('Temporarily skipped: app HandLexer build path includes vendor-dependent adapter classes.');

it('documents known preg_match lookahead divergence', function () {
    $file = 'tests/programs/hand_lexer/preg_probe_open.php';
    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->not->toBe($php_output);
});

it('documents known regex matrix divergence', function () {
    $file = 'tests/programs/hand_lexer/preg_probe_matrix.php';
    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->not->toBe($php_output);
});

it('matches PHP oracle for strict string equality in compiled output', function () {
    $file = 'tests/programs/hand_lexer/string_ops_probe_steps.php';
    /** @phpstan-ignore-next-line */
    $this->assertPicohpExitCode("build --debug {$file}");

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});

it('runs nikic-style HandLexer under PHP', function () {
    $lexer = new Lexer('<?php echo 2;');
    $tokens = $lexer->tokenize();
    $last = $tokens[count($tokens) - 1];
    expect($last->type)->toBe(TokenType::Eof);
});
