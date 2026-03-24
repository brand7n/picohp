<?php

declare(strict_types=1);

/** @var array<int, int> $a */
$a = [1, 2, 3];

/** @var array<int, int> $b */
$b = [10, 20, 30];

/** @var int $x */
$x = 0;

/** @phpstan-ignore-next-line */
foreach ($a as $x) {
    echo $x;
    echo "\n";
}

/** @phpstan-ignore-next-line */
foreach ($b as $x) {
    echo $x;
    echo "\n";
}
