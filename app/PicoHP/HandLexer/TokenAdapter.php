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
            return $e === '1' || strtolower($e) === 'true';
        }

        // Default matches app/config.php (use_native_lexer is env-only; picohp has no Laravel config() when compiled).
        return false;
    }
}
