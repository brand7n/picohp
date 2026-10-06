<?php

declare(strict_types=1);

function add(int $a, int $b): int {
    return $a + $b;
}

$x = add(1, 2);
echo $x, "\n";
