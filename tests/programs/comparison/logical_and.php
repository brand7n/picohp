<?php

function test_logical_and(): int
{
    /** @var int $a */
    $a = 1;
    /** @var int $b */
    $b = 0;

    echo $a > 0 && $a < 5;
    echo $b > 0 && $a < 5;
    echo $a > 0 && $b > 0;
    return 0;
}

test_logical_and();
