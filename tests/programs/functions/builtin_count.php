<?php

declare(strict_types=1);

/** @var array<int, string> */
$names = ['alice', 'bob', 'charlie'];

echo count($names);
echo "\n";

$names[] = 'dave';

echo count($names);
echo "\n";
