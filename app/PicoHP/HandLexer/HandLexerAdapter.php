<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

use PhpParser\ErrorHandler;
use PhpParser\Token;

/**
 * {@see \PhpParser\Lexer} with a pluggable token source: Zend ({@see Token::tokenize()}) or PicoHP’s
 * {@see NativeTokenPipeline} (custom {@see Lexer}; tokens are still {@see Token} for php-parser).
 */
class HandLexerAdapter extends \PhpParser\Lexer
{
    /**
     * Zend branch uses {@see Token::tokenize()} then {@see \PhpParser\Lexer::postprocessTokens}. Native branch
     * builds {@see Token} via constructor from {@see NativeTokenPipeline} (no Zend tokenization).
     *
     * @return list<\PhpParser\Token>
     */
    public function tokenize(string $code, ?ErrorHandler $errorHandler = null): array
    {
        if ($errorHandler === null) {
            $errorHandler = new ErrorHandler\Throwing();
        }

        if (TokenAdapter::useNativeLexer()) {
            return NativeTokenPipeline::tokenizeAndPostprocess($code, $errorHandler);
        }

        return self::tokenizeZend($code, $errorHandler);
    }

    /**
     * Zend-based tokenization fallback. Separated into its own method so the
     * compiler can stub it independently without affecting the native path.
     *
     * @return list<Token>
     */
    private static function tokenizeZend(string $code, ErrorHandler $errorHandler): array
    {
        /** @var list<Token> $tokens */
        $tokens = array_values(Token::tokenize($code));
        self::doPostprocess($tokens, $errorHandler);

        return $tokens;
    }

    /**
     * Inline postprocessing — adds sentinel token. Avoids parent::postprocessTokens()
     * which the compiler can't resolve through inheritance during self-compilation.
     *
     * @param list<Token> $tokens
     */
    private static function doPostprocess(array &$tokens, ErrorHandler $errorHandler): void
    {
        $numTokens = count($tokens);
        if ($numTokens === 0) {
            $tokens[] = new Token(0, "\0", 1, 0);

            return;
        }

        $lastToken = $tokens[$numTokens - 1];
        $tokens[] = new Token(0, "\0", $lastToken->getEndLine(), $lastToken->getEndPos());
    }
}
