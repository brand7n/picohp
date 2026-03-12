<?php

function test_unary_minus(int $a): int
{
    /** @var int */
    $b = -$a;
    echo $b;
    /** @var int */
    $c = -5;
    echo $c;
    return 0;
}

test_unary_minus(42);
