<?php

function test_greater_equal(): int
{
    /** @var int $a */
    $a = 3;
    /** @var int $b */
    $b = 2;
    /** @var int $c */
    $c = 2;

    echo $a >= $b;
    echo $b >= $c;
    echo $b >= $a;
    return 0;
}

test_greater_equal();
