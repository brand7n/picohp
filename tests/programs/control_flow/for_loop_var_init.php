<?php

function test_for_var_init(): int
{
    for (/** @var int */ $i = 0; $i < 5; $i++) {
        echo $i;
    }
    return 0;
}

test_for_var_init();
