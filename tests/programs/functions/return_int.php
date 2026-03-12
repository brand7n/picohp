<?php

function add(int $a, int $b): int
{
    return $a + $b;
}

function mul(int $a, int $b): int
{
    return $a * $b;
}

echo add(3, 4);
echo mul(add(2, 3), 4);
