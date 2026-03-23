<?php

declare(strict_types=1);

function getAsFloat(int|float $x): float
{
    return (float) $x;
}

$a = getAsFloat(7);
echo $a;
echo "\n";
$b = getAsFloat(1.5);
echo $b;
echo "\n";
