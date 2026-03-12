<?php

function test_combined(): int
{
    /** @var int $x */
    $x = 5;
    /** @var int $y */
    $y = 3;
    /** @var int $z */
    $z = 10;

    echo $x >= 1 && $x <= $z;
    echo $x >= 1 && $x <= $y;
    echo $x !== $x + $y || $x > $y;
    echo $x !== $x + $y || $x > $z;
    return 0;
}

test_combined();
