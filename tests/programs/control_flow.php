<?php

declare(strict_types=1);

$x = 0;
while ($x < 5) {
    $x = $x + 1;
}
echo $x, "\n";

if ($x > 3) {
    echo "big\n";
} else {
    echo "small\n";
}

$y = 0;
for ($i = 0; $i < 3; $i = $i + 1) {
    $y = $y + $i;
}
echo $y, "\n";
