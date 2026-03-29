<?php

declare(strict_types=1);

use App\PicoHP\HandLexer\HandLexerAdapter;
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
