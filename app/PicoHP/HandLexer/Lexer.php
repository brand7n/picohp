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
        $tag = substr($rest, 0, 5);
        if (strtolower($tag) === '<?php') {
            $next = substr($rest, 5, 1);
            $isWs = $next === ''
                || $next === ' '
                || $next === "\t"
                || $next === "\r"
                || $next === "\n";
            if ($isWs) {
                $this->pos += 5;
                $this->state = LexerState::InScripting;

                return new Token(TokenType::OpenTag, $tag, $this->line);
            }
        }

        if (preg_match('/^[^<]+/', $rest, $m) === 1) {
            $startLine = $this->line;
            $this->line += $this->countNewlines($m[0]);
            $this->pos += strlen($m[0]);

            return new Token(TokenType::InlineHtml, $m[0], $startLine);
        }

        if (substr($rest, 0, 1) === '<' && substr($rest, 1, 1) !== '?') {
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

            return new Token(TokenType::String, $m[0], $line);
        }

        // Use substr(), not $src[$i]: PicoHP string indexing yields int (byte), PHP yields string.
        // https://github.com/brand7n/picohp/issues/169
        $char = substr($src, $this->pos, 1);
        $this->pos += 1;

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
            '(' => TokenType::LeftParen,
            ')' => TokenType::RightParen,
            '<' => TokenType::LessThan,
            '>' => TokenType::GreaterThan,
            '{' => TokenType::LeftBrace,
            '}' => TokenType::RightBrace,
            '[' => TokenType::LeftBracket,
            ']' => TokenType::RightBracket,
            ',' => TokenType::Comma,
            '?' => TokenType::QuestionMark,
            '"' => TokenType::DoubleQuote,
            "'" => TokenType::SingleQuote,
            '/' => TokenType::Slash,
            '\\' => TokenType::Backslash,
            '.' => TokenType::Dot,
            '!' => TokenType::Exclamation,
            '*' => TokenType::Asterisk,
            '%' => TokenType::Percent,
            '&' => TokenType::Ampersand,
            '|' => TokenType::Pipe,
            '~' => TokenType::Tilde,
            '@' => TokenType::At,
            ':' => TokenType::Colon,
            '#' => TokenType::Hash,
            '^' => TokenType::Caret,
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
                $count += 1;
            }
            $i += 1;
        }

        return $count;
    }
}
