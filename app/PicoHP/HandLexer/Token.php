<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $value,
        public readonly int $line,
    ) {
    }
}
