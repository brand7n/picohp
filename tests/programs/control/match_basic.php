<?php

declare(strict_types=1);

function describeNumber(int $x): string
{
    return match($x) {
        1 => "one",
        2 => "two",
        3 => "three",
        default => "other",
    };
}

echo describeNumber(1) . "\n";
echo describeNumber(2) . "\n";
echo describeNumber(3) . "\n";
echo describeNumber(99) . "\n";
