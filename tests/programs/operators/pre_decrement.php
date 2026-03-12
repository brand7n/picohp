<?php

function test_predec(): int
{
    /** @var int */
    $i = 5;
    /** @var int */
    $result = --$i;
    echo $result;
    echo $i;
    return 0;
}

test_predec();
