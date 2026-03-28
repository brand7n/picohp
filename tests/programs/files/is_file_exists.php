<?php

declare(strict_types=1);

$marker = 'tests/programs/files/marker.txt';
$missing = 'tests/programs/files/no_such_file_xyz.bin';

if (is_file($marker)) {
    echo '1';
} else {
    echo '0';
}
echo "\n";

if (is_file($missing)) {
    echo '1';
} else {
    echo '0';
}
echo "\n";

if (file_exists($marker)) {
    echo '1';
} else {
    echo '0';
}
echo "\n";

if (file_exists($missing)) {
    echo '1';
} else {
    echo '0';
}
echo "\n";
