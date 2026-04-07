<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

use PhpParser\Error;
use PhpParser\ErrorHandler;
use PhpParser\Token;

/**
 * Native {@see Lexer} + {@see Token} + postprocess (logic aligned with {@see \PhpParser\Lexer::postprocessTokens}).
 *
 * Tokens are built with {@see Token}'s constructor from our scan — not {@see Token::tokenize()} (Zend). On PHP 8+ that
 * class extends native {@see \PhpToken}; the parser still requires this type for {@see \PhpParser\ParserAbstract}.
 */
final class NativeTokenPipeline
{
    /**
     * @return list<\PhpParser\Token>
     */
    public static function tokenizeAndPostprocess(string $code, ErrorHandler $errorHandler): array
    {
        $lexer = new Lexer($code);
        $raw = $lexer->tokenize();
        $tokens = [];
        $pos = 0;
        foreach ($raw as $t) {
            if ($t->type === TokenType::Eof) {
                break;
            }
            $tokens[] = new Token($t->type, $t->value, $t->line, $pos);
            $pos += \strlen($t->value);
        }
        $tokens = self::postprocessTokens($tokens, $errorHandler);

        return $tokens;
    }

    /**
     * @param list<\PhpParser\Token> $tokens
     * @return list<\PhpParser\Token>
     */
    private static function postprocessTokens(array $tokens, ErrorHandler $errorHandler): array
    {
        $numTokens = \count($tokens);
        if ($numTokens === 0) {
            $tokens[] = new Token(0, "\0", 1, 0);

            return $tokens;
        }

        for ($i = 0; $i < $numTokens; $i++) {
            $token = $tokens[$i];
            if ($token->id === \T_BAD_CHARACTER) {
                self::handleInvalidCharacter($token, $errorHandler);
            }

            if ($token->id === \ord('&')) {
                $next = $i + 1;
                while (isset($tokens[$next]) && $tokens[$next]->id === \T_WHITESPACE) {
                    $next++;
                }
                $followedByVarOrVarArg = isset($tokens[$next])
                    && ($tokens[$next]->id === \T_VARIABLE || $tokens[$next]->id === \T_ELLIPSIS);
                $token->id = $followedByVarOrVarArg
                    ? \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
                    : \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG;
            }
        }

        $lastToken = $tokens[$numTokens - 1];
        if (self::isUnterminatedComment($lastToken)) {
            $errorHandler->handleError(new Error('Unterminated comment', [
                'startLine' => $lastToken->line,
                'endLine' => $lastToken->getEndLine(),
                'startFilePos' => $lastToken->pos,
                'endFilePos' => $lastToken->getEndPos(),
            ]));
        }

        $tokens[] = new Token(0, "\0", $lastToken->getEndLine(), $lastToken->getEndPos());

        return $tokens;
    }

    private static function handleInvalidCharacter(Token $token, ErrorHandler $errorHandler): void
    {
        $chr = $token->text;
        if ($chr === "\0") {
            $errorMsg = 'Unexpected null byte';
        } else {
            // String concat instead of sprintf — picohp does not implement sprintf yet (self-compile).
            $errorMsg = 'Unexpected character "' . $chr . '" (ASCII ' . \strval(\ord($chr)) . ')';
        }

        $errorHandler->handleError(new Error($errorMsg, [
            'startLine' => $token->line,
            'endLine' => $token->line,
            'startFilePos' => $token->pos,
            'endFilePos' => $token->pos,
        ]));
    }

    private static function isUnterminatedComment(Token $token): bool
    {
        return ($token->id === \T_COMMENT || $token->id === \T_DOC_COMMENT)
            && \substr($token->text, 0, 2) === '/*'
            && \substr($token->text, -2) !== '*/';

    }
}
