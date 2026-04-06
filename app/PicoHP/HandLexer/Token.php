<?php

declare(strict_types=1);

namespace App\PicoHP\HandLexer;

final class Token
{
    public readonly int $type;
    public readonly string $value;
    public readonly int $line;

    public function __construct(int $type, string $value, int $line)
    {
        $this->type = $type;
        $this->value = $value;
        $this->line = $line;
    }
}
