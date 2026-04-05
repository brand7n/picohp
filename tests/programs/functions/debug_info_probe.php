<?php

declare(strict_types=1);

function add(int $a, int $b): int
{
    return $a + $b;
}

$result = add(3, 4);
echo $result . "\n";
