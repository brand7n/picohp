<?php

function test_elseif(int $x): int
{
    if ($x > 10) {
        echo 3;
    } elseif ($x > 5) {
        echo 2;
    } elseif ($x > 0) {
        echo 1;
    } else {
        echo 0;
    }
    return 0;
}

test_elseif(15);
test_elseif(7);
test_elseif(3);
test_elseif(-1);
