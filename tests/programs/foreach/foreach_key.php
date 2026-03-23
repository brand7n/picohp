<?php

declare(strict_types=1);

/** @var array<int, string> */
$names = ['alice', 'bob', 'charlie'];

foreach ($names as $i => $name) {
    echo $i;
    echo ": ";
    echo $name;
    echo "\n";
}
