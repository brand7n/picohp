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
     * @return array<int, Token>
     */
    public function tokenize(): array
    {
        /** @var array<int, Token> $tokens */
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
        if (preg_match('/^<\?php/i', $rest, $m) === 1) {
            $next = substr($rest, 5, 1);
            if ($next === '' || preg_match('/^[ \t\r\n]/', $next) === 1) {
                $this->pos = $this->pos + strlen($m[0]);
                $this->state = LexerState::InScripting;

                return new Token(TokenType::OpenTag, $m[0], $this->line);
            }
        }

        if (preg_match('/^[^<]+/', $rest, $m) === 1) {
            $startLine = $this->line;
            $this->line = $this->line + $this->countNewlines($m[0]);
            $this->pos = strlen($m[0]) + $this->pos;

            return new Token(TokenType::InlineHtml, $m[0], $startLine);
        }

        if (preg_match('/^</', $rest) === 1 && preg_match('/^<\?/', $rest) !== 1) {
            $this->pos = $this->pos + 1;

            return new Token(TokenType::InlineHtml, '<', $this->line);
        }

        $char = $src[$this->pos];
        $this->pos = $this->pos + 1;

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
            $this->line = $this->line + $this->countNewlines($m[0]);
            $this->pos = strlen($m[0]) + $this->pos;

            return new Token(TokenType::Whitespace, $m[0], $line);
        }

        if (preg_match('/^(\/\/|#)[^\r\n]*/', $rest, $m) === 1) {
            $this->pos = strlen($m[0]) + $this->pos;

            return new Token(TokenType::Comment, $m[0], $line);
        }

        if (preg_match('/^\/\*.*?\*\//s', $rest, $m) === 1) {
            $this->line = $this->line + $this->countNewlines($m[0]);
            $this->pos = strlen($m[0]) + $this->pos;

            return new Token(TokenType::Comment, $m[0], $line);
        }

        if (preg_match('/^\?>/', $rest, $m) === 1) {
            $this->pos = strlen($m[0]) + $this->pos;
            $this->state = LexerState::Initial;

            return new Token(TokenType::CloseTag, $m[0], $line);
        }

        if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*/', $rest, $m) === 1) {
            $this->pos = strlen($m[0]) + $this->pos;

            return new Token($this->keywordOrIdent($m[0]), $m[0], $line);
        }

        if (preg_match('/^\$[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*/', $rest, $m) === 1) {
            $this->pos = strlen($m[0]) + $this->pos;

            return new Token(TokenType::Variable, $m[0], $line);
        }

        if (preg_match('/^(\d+\.\d*|\d*\.\d+)([eE][+-]?\d+)?/', $rest, $m) === 1) {
            $this->pos = strlen($m[0]) + $this->pos;

            return new Token(TokenType::DNumber, $m[0], $line);
        }

        if (preg_match('/^0x[0-9a-fA-F]+|^0b[01]+|^0o[0-7]+|^\d+/', $rest, $m) === 1) {
            $this->pos = strlen($m[0]) + $this->pos;

            return new Token(TokenType::LNumber, $m[0], $line);
        }

        if (preg_match('/^\'(?:[^\'\\\\]|\\\\.)*\'/', $rest, $m) === 1) {
            $this->line = $this->line + $this->countNewlines($m[0]);
            $this->pos = strlen($m[0]) + $this->pos;

            return new Token(TokenType::String, $m[0], $line);
        }

        $char = $src[$this->pos];
        $this->pos = $this->pos + 1;

        return new Token($this->singleChar($char), $char, $line);
    }

    private function keywordOrIdent(string $word): TokenType
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

        return TokenType::Ident;
    }

    private function singleChar(string $char): TokenType
    {
        return match ($char) {
            ';' => TokenType::Semicolon,
            '=' => TokenType::Equals,
            '+' => TokenType::Plus,
            '-' => TokenType::Minus,
            default => TokenType::BadChar,
        };
    }

    private function countNewlines(string $text): int
    {
        $count = 0;
        $i = 0;
        $length = strlen($text);
        while ($i < $length) {
            if ($text[$i] === "\n") {
                $count = $count + 1;
            }
            $i = $i + 1;
        }

        return $count;
    }
}
