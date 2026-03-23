<?php

declare(strict_types=1);

function toWord(int $x): string
{
    return match($x) {
        0 => "zero",
        1 => "one",
        2 => "two",
        default => "unknown",
    };
}

echo toWord(0) . "\n";
echo toWord(1) . "\n";
echo toWord(2) . "\n";
echo toWord(9) . "\n";
