<?php

declare(strict_types=1);

$dir = dirname(__FILE__);

if (is_file($dir . '/marker.txt')) {
    echo "is_file: yes\n";
} else {
    echo "is_file: no\n";
}

if (is_dir($dir)) {
    echo "is_dir: yes\n";
} else {
    echo "is_dir: no\n";
}

if (file_exists($dir . '/marker.txt')) {
    echo "file_exists: yes\n";
} else {
    echo "file_exists: no\n";
}

if (file_exists($dir . '/nonexistent.txt')) {
    echo "missing: yes\n";
} else {
    echo "missing: no\n";
}

$content = file_get_contents($dir . '/marker.txt');
echo "content: " . $content;

echo "done\n";
