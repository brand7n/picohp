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
     * @return list<Token>
     */
    public function tokenize(string $code, ?ErrorHandler $errorHandler = null): array
    {
        if (null === $errorHandler) {
            $errorHandler = new ErrorHandler\Throwing();
        }

        $scream = ini_set('xdebug.scream', '0');

        try {
            if (TokenAdapter::useNativeLexer()) {
                return NativeTokenPipeline::tokenizeAndPostprocess($code, $errorHandler);
            }

            /** @var list<Token> $tokens */
            $tokens = array_values(@Token::tokenize($code));
            $this->postprocessTokens($tokens, $errorHandler);

            return $tokens;
        } finally {
            if (false !== $scream) {
                ini_set('xdebug.scream', $scream);
            }
        }
    }
}
