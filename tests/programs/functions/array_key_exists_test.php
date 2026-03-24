<?php

declare(strict_types=1);

/** @var array<string, int> $data */
$data = ['a' => 1, 'b' => 2];

if (array_key_exists('a', $data)) {
    echo "found a\n";
}

if (!array_key_exists('c', $data)) {
    echo "no c\n";
}
