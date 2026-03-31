<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

/**
 * Lexer backend selection (see {@see HandLexerAdapter}). This class is loaded early on directory
 * builds so Composer autoload from the compile target cannot shadow {@see \App\PicoHP\HandLexer\*}.
 */
final class TokenAdapter
{
    /**
     * Native {@see Lexer} path skips {@see \PhpParser\Token::tokenize()} (Zend); both paths emit
     * {@see \PhpParser\Token} for the parser.
     */
    public static function useNativeLexer(): bool
    {
        $e = getenv('PICOHP_USE_NATIVE_LEXER');
        if ($e !== false && $e !== '') {
            return $e === '1' || strcasecmp($e, 'true') === 0;
        }

        $v = \config('app.use_native_lexer');

        return $v === true || $v === '1' || $v === 1;
    }
}
