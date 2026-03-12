<?php

function test_less_equal(): int
{
    /** @var int $a */
    $a = 1;
    /** @var int $b */
    $b = 2;
    /** @var int $c */
    $c = 2;

    echo $a <= $b;
    echo $b <= $c;
    echo $b <= $a;
    return 0;
}

test_less_equal();
