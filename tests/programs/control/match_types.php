<?php

declare(strict_types=1);

function describeType(int $code): string
{
    return match($code) {
        0 => "zero",
        1 => "one",
        2 => "two",
        default => "other",
    };
}

function describeFloat(int $code): float
{
    return match($code) {
        0 => 0.0,
        1 => 1.5,
        default => 9.9,
    };
}

echo describeType(0) . "\n";
echo describeType(1) . "\n";
echo describeType(99) . "\n";
echo describeFloat(0);
echo "\n";
echo describeFloat(1);
echo "\n";
echo describeFloat(5);
echo "\n";
