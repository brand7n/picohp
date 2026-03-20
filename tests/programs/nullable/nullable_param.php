<?php

declare(strict_types=1);

function greet(?string $name): string
{
    return 'hello ' . ($name ?? 'world');
}

echo greet('alice');
echo "\n";
echo greet(null);
echo "\n";
