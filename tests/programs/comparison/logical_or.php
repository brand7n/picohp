<?php

function test_logical_or(): int
{
    /** @var int $a */
    $a = 1;
    /** @var int $b */
    $b = 0;
    /** @var int $c */
    $c = -1;

    echo $a > 0 || $b > 0;
    echo $b > 0 || $a > 0;
    echo $c > 0 || $b > 0;
    return 0;
}

test_logical_or();
