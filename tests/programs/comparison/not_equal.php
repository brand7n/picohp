<?php

function test_not_equal(): int
{
    /** @var int $a */
    $a = 1;
    /** @var int $b */
    $b = 2;
    /** @var int $c */
    $c = 1;

    echo $a !== $b;
    echo $a !== $c;
    return 0;
}

test_not_equal();
