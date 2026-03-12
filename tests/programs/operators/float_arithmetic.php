<?php

function test_float_arithmetic(): int
{
    /** @var float $a */
    $a = 3.5;
    /** @var float $b */
    $b = 1.5;

    echo $a + $b;
    echo $a - $b;
    echo $a * $b;
    echo $a / $b;
    return 0;
}

test_float_arithmetic();
