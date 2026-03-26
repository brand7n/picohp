<?php

declare(strict_types=1);

use App\PicoHP\HandLexer\Lexer;
use App\PicoHP\HandLexer\TokenType;

it('matches PHP oracle for minimal PicoHP lexer program', function () {
    $file = 'tests/programs/hand_lexer/minimal_picohp_lexer.php';
    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});

it('matches PHP oracle for HandLexer script in app/', function () {
    $file = 'tests/programs/hand_lexer/combined_hand_lexer_oracle.php';
    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->toBe($php_output);
});

it('documents known preg_match lookahead divergence', function () {
    $file = 'tests/programs/hand_lexer/preg_probe_open.php';
    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->not->toBe($php_output);
});

it('documents known regex matrix divergence', function () {
    $file = 'tests/programs/hand_lexer/preg_probe_matrix.php';
    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

    $buildPath = config('app.build_path');
    assert(is_string($buildPath));
    $compiled_output = shell_exec("{$buildPath}/a.out");
    $php_output = shell_exec("php {$file}");

    expect($compiled_output)->not->toBe($php_output);
});

it('matches PHP oracle for strict string equality in compiled output', function () {
    $file = 'tests/programs/hand_lexer/string_ops_probe_steps.php';
    /** @phpstan-ignore-next-line */
    $this->artisan("build --debug {$file}")->assertExitCode(0);

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
