<?php

function test_concat(): int
{
    /** @var string */
    $a = "Hello";
    /** @var string */
    $b = " World";
    echo $a . $b;
    echo "foo" . "bar";
    return 0;
}

test_concat();
