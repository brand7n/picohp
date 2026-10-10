<?php

declare(strict_types=1);

namespace PicoIR\Assembler;

final class Statement
{
    public const LABEL = 'label';
    public const DIRECTIVE = 'directive';
    public const INSTRUCTION = 'instruction';

    /**
     * @param list<list<Token>> $operands
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly array $operands,
        public readonly int $line,
    ) {
    }
}
