<?php

declare(strict_types=1);

/** @var array<int, int> $nums */
$nums = [10, 20, 30, 40, 50];

$idx = array_search(30, $nums, true);
echo $idx;
echo "\n";

$last = end($nums);
echo $last;
echo "\n";

array_splice($nums, 1, 2);
echo count($nums);
echo "\n";
