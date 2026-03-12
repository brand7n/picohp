<?php

function test_shifts(int $a): int
{
    echo $a << 2;
    echo $a >> 1;
    return 0;
}

test_shifts(8);
