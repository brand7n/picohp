<?php

declare(strict_types=1);

function withFloat(float $x = 1.5): float
{
    return $x;
}

function withBool(bool $flag = true): bool
{
    return $flag;
}

function withNull(?string $s = null): string
{
    return $s ?? "default";
}

/**
 * @param array<int, int> $items
 */
function withArray(array $items = []): int
{
    return count($items);
}

function withNegative(int $n = -1): int
{
    return $n;
}

echo withFloat();
echo "\n";
echo withFloat(2.5);
echo "\n";
if (withBool()) {
    echo "true\n";
}
echo withNull() . "\n";
echo withNull("hello") . "\n";
echo withArray();
echo "\n";
echo withNegative();
echo "\n";
