<?php

declare(strict_types=1);

$i = 0;
$c = 0;
while ($i < 5) {
    $i = $i + 1;
    if ($i === 2) {
        continue;
    }
    $c = $c + 1;
}
echo $c;
