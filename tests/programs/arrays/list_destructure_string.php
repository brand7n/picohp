<?php

declare(strict_types=1);

/** @var array<int, string> $parts */
$parts = ["hello", "world"];

/** @var string $first */
/** @var string $second */
[$first, $second] = $parts;

echo $first . "\n";
echo $second . "\n";
