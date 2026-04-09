<?php

declare(strict_types=1);

function firstInt(?int $a, int $b): int
{
    return $a ?? $b;
}

echo firstInt(10, 99) . "\n";
echo firstInt(null, 99) . "\n";

function firstBool(?bool $a, bool $b): bool
{
    return $a ?? $b;
}

echo (firstBool(true, false) ? '1' : '0') . "\n";
echo (firstBool(null, true) ? '1' : '0') . "\n";
