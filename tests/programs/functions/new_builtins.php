<?php

declare(strict_types=1);

// class_exists
if (class_exists('stdClass')) {
    echo "class_exists: yes\n";
}

// realpath
$path = realpath('.');
$len = strlen($path);
if ($len > 0) {
    echo "realpath: ok\n";
}

// is_file on self
if (is_file(__FILE__)) {
    echo "is_file_self: yes\n";
}

echo "done\n";
