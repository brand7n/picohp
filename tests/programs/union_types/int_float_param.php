<?php

declare(strict_types=1);

function addNumbers(int|float $a, int|float $b): float
{
    return (float) $a + (float) $b;
}

$r1 = addNumbers(10, 20);
echo $r1;
echo "\n";
$r2 = addNumbers(1.5, 2.5);
echo $r2;
echo "\n";
