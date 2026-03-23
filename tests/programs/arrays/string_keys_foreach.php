<?php

declare(strict_types=1);

/** @var array<string, int> $data */
$data = ['a' => 1, 'b' => 2, 'c' => 3];

/** @var string $key */
/** @var int $val */
foreach ($data as $key => $val) {
    echo $key;
    echo "=";
    echo $val;
    echo "\n";
}
