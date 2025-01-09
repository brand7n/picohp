<?php

function main(): int
{
    echo fixed_to_float(1234, 8);
    /** @var int */
    $a = 5 + 4 * 3;
    /** @var int */
    $b = $a;
    echo (float)$b;
    return $a / 2;
}
