<?php

declare(strict_types=1);

namespace PhpParser;

/**
 * Compat stub for self-compilation. Standalone Token class that doesn't
 * extend \PhpToken (unavailable in compiled binaries).
 */
class Token
{
    public int $id;
    public string $text;
    public int $line;
    public int $pos;

    public function __construct(int $id, string $text, int $line = -1, int $pos = -1)
    {
        $this->id = $id;
        $this->text = $text;
        $this->line = $line;
        $this->pos = $pos;
    }

    public function getEndPos(): int
    {
        return $this->pos + strlen($this->text);
    }

    public function getEndLine(): int
    {
        return $this->line + substr_count($this->text, "\n");
    }

    /**
     * @param int|string|array<int|string> $kind
     */
    public function is(int|string|array $kind): bool
    {
        if (is_array($kind)) {
            /** @var int|string $k */
            foreach ($kind as $k) {
                if (is_string($k)) {
                    if ($this->text === $k) {
                        return true;
                    }
                } elseif ($this->id === $k) {
                    return true;
                }
            }

            return false;
        }
        if (is_string($kind)) {
            return $this->text === $kind;
        }

        return $this->id === $kind;
    }

    /**
     * @return list<self>
     */
    public static function tokenize(string $code): array
    {
        // In compiled binaries this delegates to the HandLexer via
        // the compat Lexer. This static method exists for API compat.
        return [];
    }
}
