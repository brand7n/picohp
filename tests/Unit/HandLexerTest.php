<?php

declare(strict_types=1);

use App\PicoHP\HandLexer\Lexer;
use App\PicoHP\HandLexer\TokenType;

it('tokenizes a small script into expected kinds', function () {
    $lexer = new Lexer('<?php echo 1;');
    $tokens = $lexer->tokenize();

    $types = array_map(
        static fn ($t) => $t->type,
        $tokens,
    );

    expect($types)->toBe([
        TokenType::OpenTag,
        TokenType::Whitespace,
        TokenType::Echo,
        TokenType::Whitespace,
        TokenType::LNumber,
        TokenType::Semicolon,
        TokenType::Eof,
    ]);
});

it('records start line on open tag', function () {
    $lexer = new Lexer('<?php echo 1;');
    $tokens = $lexer->tokenize();
    expect($tokens[0]->line)->toBe(1);
    expect($tokens[0]->value)->toBe('<?php');
});
