<?php

declare(strict_types=1);

namespace PicoIR\Assembler;

final class Token
{
    public const IDENT = 'ident';
    public const NUMBER = 'number';
    public const PUNCT = 'punct';
    public const NEWLINE = 'newline';
    public const EOF = 'eof';

    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int $line,
    ) {
    }
}
