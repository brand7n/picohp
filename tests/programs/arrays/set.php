<?php

declare(strict_types=1);

/** @var array<int, string> */
$names = ['alice', 'bob'];

$names[1] = 'carol';

echo $names[0];
echo "\n";
echo $names[1];
echo "\n";
