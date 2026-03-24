<?php

declare(strict_types=1);

/** @return array<int, int> */
function getEmpty(): array
{
    return [];
}

/** @var array<int, int> $arr */
$arr = getEmpty();
echo count($arr);
echo "\n";
