<?php

declare(strict_types=1);

namespace PicoIR\Assembler\Output;

/** Serializes 32-bit instruction words to little-endian bytes. */
final class RawBinary
{
    /** @param list<int> $words */
    public function encode(array $words): string
    {
        $out = '';
        foreach ($words as $word) {
            $out .= pack('V', $word);
        }
        return $out;
    }
}
