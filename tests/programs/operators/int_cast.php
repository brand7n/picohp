<?php

function test_int_cast(): int
{
    /** @var float */
    $f = 3.7;
    echo (int)$f;
    /** @var bool */
    $t = true;
    /** @var bool */
    $fv = false;
    echo (int)$t;
    echo (int)$fv;
    return 0;
}

test_int_cast();
