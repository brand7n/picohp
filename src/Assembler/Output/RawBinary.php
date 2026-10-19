<?php

declare(strict_types=1);

namespace PicoIR\Assembler\Output;

use Remodulate\Pico\PicoString;
use Remodulate\Pico\StringBuilder;

/** Serializes 32-bit instruction words to little-endian bytes. */
final class RawBinary
{
    /** @param list<int> $words */
    public function encode(array $words): PicoString
    {
        $out = new StringBuilder();
        foreach ($words as $word) {
            $out->appendU32LE($word);
        }
        return $out->toString();
    }
}
