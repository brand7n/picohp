<?php

declare(strict_types=1);

/** @var array<int, string> $m */
$m = [];

$result = preg_match('/^hello/', 'world', $m);
echo $result;
echo "\n";

$result2 = preg_match('/(\d+)/', 'abc123def', $m);
echo $result2;
echo "\n";

if ($result2 === 1) {
    echo $m[1];
    echo "\n";
}
