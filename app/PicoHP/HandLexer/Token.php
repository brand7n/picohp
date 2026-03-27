<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

final class Token
{
    public readonly TokenType $type;
    public readonly string $value;
    public readonly int $line;

    public function __construct(TokenType $type, string $value, int $line)
    {
        // Use explicit property declarations because constructor promoted properties
        // are not fully supported by PicoHP’s current IR pipeline.
        $this->type = $type;
        $this->value = $value;
        $this->line = $line;
    }
}