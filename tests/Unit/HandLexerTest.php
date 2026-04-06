<?php

declare(strict_types=1);

use App\PicoHP\HandLexer\HandLexerAdapter;
use App\PicoHP\HandLexer\Lexer;
use App\PicoHP\HandLexer\TokenAdapter;
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
        TokenType::Echo,
        TokenType::Whitespace,
        TokenType::LNumber,
        TokenType::Semicolon,
        TokenType::Eof,
    ]);
});

it('tokenizes ? for nullable types as PHP does (ASCII 63, not T_BAD_CHARACTER)', function () {
    $lexer = new Lexer('<?php public ?string $x;');
    $tokens = $lexer->tokenize();
    $marks = array_values(array_filter(
        $tokens,
        static fn ($t) => $t->value === '?',
    ));
    expect($marks)->toHaveCount(1);
    expect($marks[0]->type)->toBe(TokenType::QuestionMark);
});

it('records start line on open tag', function () {
    $lexer = new Lexer('<?php echo 1;');
    $tokens = $lexer->tokenize();
    expect($tokens[0]->line)->toBe(1);
    expect($tokens[0]->value)->toBe('<?php ');
});

it('covers scripting tokens, comments, literals, keywords, close tag, and inline html', function () {
    $src = <<<'SRC'
pre
<?php
// line
/* block */
$a = 1.5e1; 0x10; 0b11; 'sq';
if ($x) {} else {}
while (0) {}
for (;;) {}
foreach ($a as $b) {}
function f() {}
return 1;
class C {}
new C;
ident;
?>post
SRC;
    $lexer = new Lexer($src);
    $tokens = $lexer->tokenize();
    expect(count($tokens))->toBeGreaterThan(40);
});

it('tokenizes via HandLexerAdapter for PhpParser', function () {
    $adapter = new HandLexerAdapter();
    $tokens = $adapter->tokenize('<?php echo 1;');
    expect(count($tokens))->toBeGreaterThan(1);
});

it('switches to native lexer when PICOHP_USE_NATIVE_LEXER=1', function () {
    $prev = getenv('PICOHP_USE_NATIVE_LEXER');
    putenv('PICOHP_USE_NATIVE_LEXER=1');
    try {
        expect(TokenAdapter::useNativeLexer())->toBeTrue();
        $adapter = new HandLexerAdapter();
        $tokens = $adapter->tokenize('<?php echo 1;');
        expect(count($tokens))->toBeGreaterThan(3);
        expect($tokens[0])->toBeInstanceOf(\PhpParser\Token::class);
    } finally {
        if ($prev === false) {
            putenv('PICOHP_USE_NATIVE_LEXER');
        } else {
            putenv('PICOHP_USE_NATIVE_LEXER=' . $prev);
        }
    }
});
