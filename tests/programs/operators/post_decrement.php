<?php

function test_postdec(): int
{
    /** @var int */
    $i = 5;
    /** @var int */
    $before = $i--;
    echo $before;
    echo $i;
    return 0;
}

test_postdec();
