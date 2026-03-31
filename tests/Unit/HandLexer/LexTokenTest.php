<?php

declare(strict_types=1);

use App\PicoHP\HandLexer\LexToken;
use PhpParser\Token as ParserToken;

it('maps PhpParser tokens to LexToken fields', function () {
    $code = '<?php echo 1;';
    $nikic = ParserToken::tokenize($code);
    $ours = LexToken::fromPhpParserTokenList($nikic);
    expect(count($ours))->toBe(count($nikic));
    foreach ($ours as $i => $t) {
        expect($t->id)->toBe($nikic[$i]->id);
        expect($t->text)->toBe($nikic[$i]->text);
        expect($t->line)->toBe($nikic[$i]->line);
        expect($t->pos)->toBe($nikic[$i]->pos);
    }
});
