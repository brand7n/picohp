<?php

function test_do_while(int $n): int
{
    /** @var int */
    $i = 0;
    do {
        echo $i;
        $i = $i + 1;
    } while ($i < $n);
    return 0;
}

test_do_while(5);
