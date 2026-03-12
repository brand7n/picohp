<?php

function test_modulo(): int
{
    /** @var int $a */
    $a = 17;
    /** @var int $b */
    $b = 5;
    /** @var int $c */
    $c = 4;

    echo $a % $b;
    echo $a % $c;
    echo $b % $b;
    return 0;
}

test_modulo();
