<?php

declare(strict_types=1);

function checkInt(int $x): void
{
    /** @phpstan-ignore-next-line */
    if (is_int($x)) {
        echo "is int\n";
    }
}

function convertFloat(float $f): int
{
    return intval($f);
}

checkInt(42);
echo convertFloat(3.7);
echo "\n";
echo convertFloat(9.1);
echo "\n";
