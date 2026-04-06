<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

final class Lexer
{
    private int $pos = 0;

    private int $line = 1;

    private LexerState $state = LexerState::Initial;

    private string $source;

    public function __construct(string $s)
    {
        $this->source = $s;
    }

    /**
     * @return array<int, \App\PicoHP\HandLexer\Token>
     */
    public function tokenize(): array
    {
        /** @var array<int, \App\PicoHP\HandLexer\Token> $tokens */
        $tokens = [];
        $running = true;
        while ($running) {
            $token = $this->nextToken();
            $tokens[] = $token;
            if ($token->type === TokenType::Eof) {
                $running = false;
            }
        }

        return $tokens;
    }

    private function nextToken(): Token
    {
        if ($this->pos >= strlen($this->source)) {
            return new Token(TokenType::Eof, '', $this->line);
        }

        return match ($this->state) {
            LexerState::Initial => $this->scanInitial(),
            LexerState::InScripting => $this->scanInScripting(),
        };
    }

    private function scanInitial(): Token
    {
        $src = $this->source;
        $pos = $this->pos;
        $rest = substr($src, $pos);

        /** @var array<int, string> $m */
        $m = [];
        // Check for <?php using ord() — string === doesn't work in compiled binaries
        if (strlen($rest) >= 5
            && ord(substr($rest, 0, 1)) === 60   // <
            && ord(substr($rest, 1, 1)) === 63   // ?
            && (ord(substr($rest, 2, 1)) === 112 || ord(substr($rest, 2, 1)) === 80)  // p/P
            && (ord(substr($rest, 3, 1)) === 104 || ord(substr($rest, 3, 1)) === 72)  // h/H
            && (ord(substr($rest, 4, 1)) === 112 || ord(substr($rest, 4, 1)) === 80)) { // p/P
            $nextOrd = strlen($rest) > 5 ? ord(substr($rest, 5, 1)) : 0;
            $isWs = $nextOrd === 0 || $nextOrd === 32 || $nextOrd === 9 || $nextOrd === 13 || $nextOrd === 10;
            if ($isWs) {
                $tagLen = 5;
                if ($nextOrd === 10) {
                    $tagLen = 6;
                    $this->line += 1;
                } elseif ($nextOrd === 13 && strlen($rest) > 6 && ord(substr($rest, 6, 1)) === 10) {
                    $tagLen = 7;
                    $this->line += 1;
                }
                $tokenText = substr($rest, 0, $tagLen);
                $this->pos += $tagLen;
                $this->state = LexerState::InScripting;

                return new Token(TokenType::OpenTag, $tokenText, $this->line);
            }
        }

        if (preg_match('/^[^<]+/', $rest, $m) === 1) {
            $startLine = $this->line;
            $this->line += $this->countNewlines($m[0]);
            $this->pos += strlen($m[0]);

            return new Token(TokenType::InlineHtml, $m[0], $startLine);
        }

        if (ord(substr($rest, 0, 1)) === 60 && ord(substr($rest, 1, 1)) !== 63) {
            $this->pos += 1;

            return new Token(TokenType::InlineHtml, '<', $this->line);
        }

        // Use substr(), not $src[$i]: PicoHP string indexing yields int (byte), PHP yields string.
        // https://github.com/brand7n/picohp/issues/169
        $char = substr($src, $this->pos, 1);
        $this->pos += 1;

        return new Token(TokenType::BadChar, $char, $this->line);
    }

    private function scanInScripting(): Token
    {
        $src = $this->source;
        $pos = $this->pos;
        $rest = substr($src, $pos);
        $line = $this->line;

        /** @var array<int, string> $m */
        $m = [];
        if (preg_match('/^[ \t\r\n]+/', $rest, $m) === 1) {
            $this->line += $this->countNewlines($m[0]);
            $this->pos += strlen($m[0]);

            return new Token(TokenType::Whitespace, $m[0], $line);
        }

        if (preg_match('/^(\/\/|#)[^\r\n]*/', $rest, $m) === 1) {
            $this->pos += strlen($m[0]);

            return new Token(TokenType::Comment, $m[0], $line);
        }

        if (preg_match('/^\/\*.*?\*\//s', $rest, $m) === 1) {
            $this->line += $this->countNewlines($m[0]);
            $this->pos += strlen($m[0]);

            return new Token(TokenType::Comment, $m[0], $line);
        }

        if (preg_match('/^\?>/', $rest, $m) === 1) {
            $this->pos += strlen($m[0]);
            $this->state = LexerState::Initial;

            return new Token(TokenType::CloseTag, $m[0], $line);
        }

        if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*/', $rest, $m) === 1) {
            $this->pos += strlen($m[0]);

            return new Token($this->keywordOrIdent($m[0]), $m[0], $line);
        }

        if (preg_match('/^\$[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*/', $rest, $m) === 1) {
            $this->pos += strlen($m[0]);

            return new Token(TokenType::Variable, $m[0], $line);
        }

        if (preg_match('/^(\d+\.\d*|\d*\.\d+)([eE][+-]?\d+)?/', $rest, $m) === 1) {
            $this->pos += strlen($m[0]);

            return new Token(TokenType::DNumber, $m[0], $line);
        }

        if (preg_match('/^0x[0-9a-fA-F]+|^0b[01]+|^0o[0-7]+|^\d+/', $rest, $m) === 1) {
            $this->pos += strlen($m[0]);

            return new Token(TokenType::LNumber, $m[0], $line);
        }

        if (preg_match('/^\'(?:[^\'\\\\]|\\\\.)*\'/', $rest, $m) === 1) {
            $this->line += $this->countNewlines($m[0]);
            $this->pos += strlen($m[0]);

            return new Token(TokenType::ConstString, $m[0], $line);
        }

        // Double-quoted strings
        if (preg_match('/^"(?:[^"\\\\]|\\\\.)*"/s', $rest, $m) === 1) {
            $this->line += $this->countNewlines($m[0]);
            $this->pos += strlen($m[0]);

            return new Token(TokenType::ConstString, $m[0], $line);
        }

        // Compound operators — use ord() for byte-level comparison
        // (string === doesn't work in compiled binaries)
        $c1 = ord(substr($rest, 0, 1));
        $c2 = ord(substr($rest, 1, 1));
        $c3 = ord(substr($rest, 2, 1));

        // Three-char operators
        $threeId = $this->threeCharOp($c1, $c2, $c3);
        if ($threeId > 0) {
            $text = substr($rest, 0, 3);
            $this->pos += 3;

            return new Token($threeId, $text, $line);
        }

        // Two-char operators
        $twoId = $this->twoCharOp($c1, $c2);
        if ($twoId > 0) {
            $text = substr($rest, 0, 2);
            $this->pos += 2;

            return new Token($twoId, $text, $line);
        }

        // Use substr(), not $src[$i]: PicoHP string indexing yields int (byte), PHP yields string.
        // https://github.com/brand7n/picohp/issues/169
        $char = substr($src, $this->pos, 1);
        $this->pos += 1;

        return new Token($this->singleChar($char), $char, $line);
    }

    private function keywordOrIdent(string $word): int
    {
        $lower = strtolower($word);
        if ($lower === 'echo') {
            return TokenType::Echo;
        }
        if ($lower === 'if') {
            return TokenType::If;
        }
        if ($lower === 'else') {
            return TokenType::Else;
        }
        if ($lower === 'while') {
            return TokenType::While;
        }
        if ($lower === 'for') {
            return TokenType::For;
        }
        if ($lower === 'foreach') {
            return TokenType::Foreach;
        }
        if ($lower === 'function') {
            return TokenType::Function;
        }
        if ($lower === 'return') {
            return TokenType::Return;
        }
        if ($lower === 'class') {
            return TokenType::ClassKeyword;
        }
        if ($lower === 'new') {
            return TokenType::New;
        }
        if ($lower === 'declare') {
            return 299; // T_DECLARE
        }

        return TokenType::Ident;
    }

    private function singleChar(string $char): int
    {
        // Use ord() + integer switch — string match doesn't work in compiled binaries
        return match (ord($char)) {
            59 => TokenType::Semicolon,
            61 => TokenType::Equals,
            43 => TokenType::Plus,
            45 => TokenType::Minus,
            40 => TokenType::LeftParen,
            41 => TokenType::RightParen,
            60 => TokenType::LessThan,
            62 => TokenType::GreaterThan,
            123 => TokenType::LeftBrace,
            125 => TokenType::RightBrace,
            91 => TokenType::LeftBracket,
            93 => TokenType::RightBracket,
            44 => TokenType::Comma,
            63 => TokenType::QuestionMark,
            34 => TokenType::DoubleQuote,
            39 => TokenType::SingleQuote,
            47 => TokenType::Slash,
            92 => TokenType::Backslash,
            46 => TokenType::Dot,
            33 => TokenType::Exclamation,
            42 => TokenType::Asterisk,
            37 => TokenType::Percent,
            38 => TokenType::Ampersand,
            124 => TokenType::Pipe,
            126 => TokenType::Tilde,
            64 => TokenType::At,
            58 => TokenType::Colon,
            35 => TokenType::Hash,
            94 => TokenType::Caret,
            default => TokenType::BadChar,
        };
    }

    /** @return int Token ID or 0 if not a three-char operator */
    private function threeCharOp(int $c1, int $c2, int $c3): int
    {
        if ($c1 === 61 && $c2 === 61 && $c3 === 61) {
            return 372;
        } // ===  T_IS_IDENTICAL
        if ($c1 === 33 && $c2 === 61 && $c3 === 61) {
            return 373;
        } // !==  T_IS_NOT_IDENTICAL
        if ($c1 === 42 && $c2 === 42 && $c3 === 61) {
            return 407;
        } // **=  T_POW_EQUAL
        if ($c1 === 60 && $c2 === 60 && $c3 === 61) {
            return 365;
        } // <<=  T_SL_EQUAL
        if ($c1 === 62 && $c2 === 62 && $c3 === 61) {
            return 366;
        } // >>=  T_SR_EQUAL
        if ($c1 === 63 && $c2 === 63 && $c3 === 61) {
            return 367;
        } // ??=  T_COALESCE_EQUAL
        if ($c1 === 46 && $c2 === 46 && $c3 === 46) {
            return 404;
        } // ...  T_ELLIPSIS
        if ($c1 === 60 && $c2 === 61 && $c3 === 62) {
            return 376;
        } // <=>  T_SPACESHIP
        if ($c1 === 63 && $c2 === 45 && $c3 === 62) {
            return 390;
        } // ?->  T_NULLSAFE_OBJECT_OPERATOR

        return 0;
    }

    /** @return int Token ID or 0 if not a two-char operator */
    private function twoCharOp(int $c1, int $c2): int
    {
        if ($c1 === 46 && $c2 === 61) {
            return 360;
        } // .=  T_CONCAT_EQUAL
        if ($c1 === 43 && $c2 === 61) {
            return 356;
        } // +=  T_PLUS_EQUAL
        if ($c1 === 45 && $c2 === 61) {
            return 357;
        } // -=  T_MINUS_EQUAL
        if ($c1 === 42 && $c2 === 61) {
            return 358;
        } // *=  T_MUL_EQUAL
        if ($c1 === 47 && $c2 === 61) {
            return 359;
        } // /=  T_DIV_EQUAL
        if ($c1 === 37 && $c2 === 61) {
            return 361;
        } // %=  T_MOD_EQUAL
        if ($c1 === 38 && $c2 === 61) {
            return 362;
        } // &=  T_AND_EQUAL
        if ($c1 === 124 && $c2 === 61) {
            return 363;
        } // |=  T_OR_EQUAL
        if ($c1 === 94 && $c2 === 61) {
            return 364;
        } // ^=  T_XOR_EQUAL
        if ($c1 === 61 && $c2 === 61) {
            return 370;
        } // ==  T_IS_EQUAL
        if ($c1 === 33 && $c2 === 61) {
            return 371;
        } // !=  T_IS_NOT_EQUAL
        if ($c1 === 60 && $c2 === 61) {
            return 374;
        } // <=  T_IS_SMALLER_OR_EQUAL
        if ($c1 === 62 && $c2 === 61) {
            return 375;
        } // >=  T_IS_GREATER_OR_EQUAL
        if ($c1 === 61 && $c2 === 62) {
            return 391;
        } // =>  T_DOUBLE_ARROW
        if ($c1 === 45 && $c2 === 62) {
            return 389;
        } // ->  T_OBJECT_OPERATOR
        if ($c1 === 58 && $c2 === 58) {
            return 402;
        } // ::  T_PAAMAYIM_NEKUDOTAYIM
        if ($c1 === 43 && $c2 === 43) {
            return 379;
        } // ++  T_INC
        if ($c1 === 45 && $c2 === 45) {
            return 380;
        } // --  T_DEC
        if ($c1 === 38 && $c2 === 38) {
            return 369;
        } // &&  T_BOOLEAN_AND
        if ($c1 === 124 && $c2 === 124) {
            return 368;
        } // ||  T_BOOLEAN_OR
        if ($c1 === 60 && $c2 === 60) {
            return 377;
        } // <<  T_SL
        if ($c1 === 62 && $c2 === 62) {
            return 378;
        } // >>  T_SR
        if ($c1 === 42 && $c2 === 42) {
            return 406;
        } // **  T_POW
        if ($c1 === 63 && $c2 === 63) {
            return 405;
        } // ??  T_COALESCE

        return 0;
    }

    private function countNewlines(string $text): int
    {
        return substr_count($text, "\n");
    }
}
