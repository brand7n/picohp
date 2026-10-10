<?php

declare(strict_types=1);

namespace PicoIR\Assembler;

use RuntimeException;

/**
 * Tokenizes GNU-style AArch64 assembly source.
 *
 * Comments are `//` and `@`. `#` is NOT a comment: in AArch64 syntax it
 * introduces immediate operands (`movz x0, #42`).
 */
final class Lexer
{
    /** @return list<Token> */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $len = strlen($source);
        $i = 0;
        $line = 1;

        while ($i < $len) {
            $ch = $source[$i];

            if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
                $i++;
                continue;
            }
            if ($ch === "\n") {
                $tokens[] = new Token(Token::NEWLINE, "\n", $line);
                $line++;
                $i++;
                continue;
            }

            if ($ch === '/' && ($source[$i + 1] ?? '') === '/') {
                while ($i < $len && $source[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($ch === '@' || $ch === ';') {
                while ($i < $len && $source[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if (str_contains(',:[]#!+-*()', $ch)) {
                $tokens[] = new Token(Token::PUNCT, $ch, $line);
                $i++;
                continue;
            }

            if (ctype_digit($ch)) {
                $start = $i;
                if ($ch === '0' && (($source[$i + 1] ?? '') === 'x' || ($source[$i + 1] ?? '') === 'X')) {
                    $i += 2;
                    while ($i < $len && ctype_xdigit($source[$i])) {
                        $i++;
                    }
                } else {
                    while ($i < $len && ctype_digit($source[$i])) {
                        $i++;
                    }
                }
                $tokens[] = new Token(Token::NUMBER, substr($source, $start, $i - $start), $line);
                continue;
            }

            if (preg_match('/[A-Za-z_.$]/', $ch)) {
                $start = $i;
                while ($i < $len && preg_match('/[A-Za-z0-9_.$]/', $source[$i])) {
                    $i++;
                }
                $tokens[] = new Token(Token::IDENT, substr($source, $start, $i - $start), $line);
                continue;
            }

            throw new RuntimeException("line $line: unexpected character '$ch'");
        }

        $tokens[] = new Token(Token::EOF, '', $line);
        return $tokens;
    }
}
