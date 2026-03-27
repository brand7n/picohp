<?php

declare(strict_types=1);

function test_plus_minus_equals(): int
{
    $a = 10;
    $a += 5;
    echo $a;
    echo "\n";
    $a -= 3;
    echo $a;
    echo "\n";
    /** @var array<int, int> $arr */
    $arr = [1, 2, 3];
    $arr[1] += 10;
    echo $arr[1];
    return 0;
}

test_plus_minus_equals();
