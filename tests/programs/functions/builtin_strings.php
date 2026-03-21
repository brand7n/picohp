<?php

declare(strict_types=1);

echo str_starts_with('hello world', 'hello');
echo "\n";
echo str_starts_with('hello world', 'world');
echo "\n";
echo str_contains('hello world', 'lo wo');
echo "\n";
echo str_contains('hello world', 'xyz');
echo "\n";
echo substr('hello world', 6, 5);
echo "\n";
echo substr('hello world', 0, 5);
echo "\n";
echo trim('  hello  ');
echo "\n";
