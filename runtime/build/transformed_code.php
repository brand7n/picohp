<?php

function main(): int
{
    declare (strict_types=1);
    echo greet('alice');
    echo "\n";
    echo greet(null);
    echo "\n";
    return 0;
}
function greet(?string $name): string
{
    return 'hello ' . ($name ?? 'world');
}