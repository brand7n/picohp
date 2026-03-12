<?php

function test_not(int $a, int $b): int
{
    if (!($a > $b)) {
        echo 1;
    } else {
        echo 0;
    }

    /** @var bool */
    $t = true;
    /** @var bool */
    $f = false;
    echo (int)(!$t);
    echo (int)(!$f);
    return 0;
}

test_not(3, 5);
test_not(5, 3);
