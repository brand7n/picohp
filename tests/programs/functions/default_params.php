<?php

declare(strict_types=1);

function greet(string $name, string $greeting = 'hello'): string
{
    return $greeting . ' ' . $name;
}

echo greet('alice', 'hi');
echo "\n";
echo greet('bob');
echo "\n";

function add(int $a, int $b = 0, int $c = 0): int
{
    return $a + $b + $c;
}

echo add(1, 2, 3);
echo "\n";
echo add(10, 20);
echo "\n";
echo add(100);
echo "\n";
