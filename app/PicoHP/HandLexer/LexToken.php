<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

/**
 * Plain token shape for tests and helpers. The php-parser pipeline always uses {@see \PhpParser\Token}
 * (required by {@see \PhpParser\ParserAbstract}); map with {@see fromPhpParserToken} when comparing.
 */
final class LexToken
{
    /** @var array<int, bool> */
    private const IGNORABLE_IDS = [
        \T_WHITESPACE => true,
        \T_COMMENT => true,
        \T_DOC_COMMENT => true,
        \T_OPEN_TAG => true,
    ];

    public function __construct(
        public int $id,
        public string $text,
        public int $line = -1,
        public int $pos = -1,
    ) {
    }

    /**
     * @param list<\PhpParser\Token> $tokens
     *
     * @return list<LexToken>
     */
    public static function fromPhpParserTokenList(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            $out[] = self::fromPhpParserToken($t);
        }

        return $out;
    }

    public static function fromPhpParserToken(\PhpParser\Token $token): self
    {
        return new self($token->id, $token->text, $token->line, $token->pos);
    }

    public function getEndPos(): int
    {
        return $this->pos + \strlen($this->text);
    }

    public function getEndLine(): int
    {
        return $this->line + \substr_count($this->text, "\n");
    }

    public function getTokenName(): ?string
    {
        if ($this->id >= 0 && $this->id < 256) {
            return \chr($this->id);
        }

        $name = \token_name($this->id);

        return $name === 'UNKNOWN' ? null : $name;
    }

    /**
     * @param mixed $kind int|string|list<int|string>
     */
    public function is($kind): bool
    {
        if (\is_int($kind)) {
            return $this->id === $kind;
        }
        if (\is_string($kind)) {
            return $this->text === $kind;
        }
        if (\is_array($kind)) {
            foreach ($kind as $entry) {
                if (\is_int($entry)) {
                    if ($this->id === $entry) {
                        return true;
                    }
                } elseif (\is_string($entry)) {
                    if ($this->text === $entry) {
                        return true;
                    }
                } else {
                    throw new \TypeError(
                        'Argument #1 ($kind) must only have elements of type string|int, '
                        . \gettype($entry) . ' given',
                    );
                }
            }

            return false;
        }
        throw new \TypeError(
            'Argument #1 ($kind) must be of type string|int|array, ' . \gettype($kind) . ' given',
        );
    }

    public function isIgnorable(): bool
    {
        return isset(self::IGNORABLE_IDS[$this->id]);
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
