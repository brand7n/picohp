<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

use PhpParser\ErrorHandler;

class HandLexerAdapter extends \PhpParser\Lexer
{
    /**
     * @return list<\PhpParser\Token>
     */
    public function tokenize(string $code, ?ErrorHandler $errorHandler = null): array
    {
        if (null === $errorHandler) {
            $errorHandler = new ErrorHandler\Throwing();
        }

        $scream = ini_set('xdebug.scream', '0');

        // our native tokenizer
        $tokens = TokenAdapter::tokenize($code);
        $this->postprocessTokens($tokens, $errorHandler);

        if (false !== $scream) {
            ini_set('xdebug.scream', $scream);
        }

        return $tokens;
    }
}
