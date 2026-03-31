<?php

declare(strict_types=1);

namespace PhpParser;

/**
 * Drop-in for {@see \PhpParser\Token} used with {@code picohp build --override-class} so reachability
 * follows this file instead of {@code vendor/nikic/php-parser}. Extends native {@see \PhpToken} only
 * (no {@code Internal\\TokenPolyfill}).
 */
class Token extends \PhpToken
{
    public function getEndPos(): int
    {
        return $this->pos + \strlen($this->text);
    }

    public function getEndLine(): int
    {
        return $this->line + \substr_count($this->text, "\n");
    }
}
