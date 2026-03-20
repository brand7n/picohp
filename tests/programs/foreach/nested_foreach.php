<?php

declare(strict_types=1);

/** @var array<int, string> */
$rows = ['a', 'b'];

/** @var array<int, string> */
$cols = ['1', '2'];

foreach ($rows as $row) {
    foreach ($cols as $col) {
        echo $row . $col;
        echo "\n";
    }
}
