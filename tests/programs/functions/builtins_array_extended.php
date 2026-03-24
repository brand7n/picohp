<?php

declare(strict_types=1);

/** @var array<int, int> $stack */
$stack = [1, 2, 3, 4, 5];

array_pop($stack);
echo count($stack);
echo "\n";

/** @var array<int, int> $reversed */
$reversed = array_reverse($stack);
echo count($reversed);
echo "\n";
