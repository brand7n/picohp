<?php

function test_float_comparison(): int
{
    /** @var float $a */
    $a = 3.5;
    /** @var float $b */
    $b = 1.5;
    /** @var float $c */
    $c = 3.5;

    echo $a > $b;
    echo $a < $b;
    echo $a === $c;
    echo $a !== $b;
    echo $a >= $c;
    echo $b <= $a;
    return 0;
}

test_float_comparison();
