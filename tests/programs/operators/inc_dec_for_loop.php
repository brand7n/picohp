<?php

function test_for_loop(): int
{
    /** @var int */
    $i = 0;
    for ($i = 0; $i < 5; $i++) {
        echo $i;
    }
    return 0;
}

test_for_loop();
