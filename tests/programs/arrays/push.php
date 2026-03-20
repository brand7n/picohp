<?php

declare(strict_types=1);

/** @var array<int, int> */
$nums = [10, 20];

$nums[] = 30;
$nums[] = 40;

foreach ($nums as $n) {
    echo $n;
    echo "\n";
}
