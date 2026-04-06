<?php

declare(strict_types=1);

namespace PhpParser;

use App\PicoHP\HandLexer\HandLexerAdapter;

/**
 * Compat stub for self-compilation. Replaces nikic's Lexer which uses
 * token_get_all(). Delegates to HandLexerAdapter (our native tokenizer).
 */
class Lexer
{
    /** @return list<Token> */
    public function tokenize(string $code, ?ErrorHandler $errorHandler = null): array
    {
        $adapter = new HandLexerAdapter();

        return $adapter->tokenize($code, $errorHandler);
    }

    /**
     * @param list<Token> $tokens
     */
    protected function postprocessTokens(array &$tokens, ErrorHandler $errorHandler): void
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
