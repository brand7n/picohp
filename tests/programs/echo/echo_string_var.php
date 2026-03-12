<?php

function test_echo_string_var(): int
{
    /** @var string $s */
    $s = "hello";
    echo $s;
    /** @var string $t */
    $t = " world";
    echo $t;
    return 0;
}

test_echo_string_var();
