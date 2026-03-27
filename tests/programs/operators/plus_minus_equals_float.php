<?php

declare(strict_types=1);

function test_float(): int
{
    $f = 1.5;
    $f += 2.5;
    echo $f;
    echo "\n";
    $f -= 1.0;
    echo $f;
    return 0;
}

test_float();
