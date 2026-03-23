<?php

declare(strict_types=1);

function describeSign(int $n): string
{
    return $n > 0 ? "positive" : "non-positive";
}

echo describeSign(5) . "\n";
echo describeSign(0) . "\n";

function maybeDouble(int $n, bool $doIt): int
{
    return $doIt ? $n * 2 : $n;
}

echo maybeDouble(10, true);
echo "\n";
echo maybeDouble(10, false);
echo "\n";
