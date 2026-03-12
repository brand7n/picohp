<?php

function test_echo_escape(): int
{
    echo "line1\nline2";
    echo "\ttab";
    return 0;
}

test_echo_escape();
