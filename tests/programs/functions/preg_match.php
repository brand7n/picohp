<?php

declare(strict_types=1);

/** @var array<int, string> $m */
$m = [];

$result = preg_match('/^array<(\w+),\s*(\w+)>$/', 'array<string, int>', $m);
echo $result;
echo "\n";

if ($result === 1) {
    echo $m[0];
    echo "\n";
    echo $m[1];
    echo "\n";
    echo $m[2];
    echo "\n";
}

$noMatch = preg_match('/^foo/', 'bar', $m);
echo $noMatch;
echo "\n";
