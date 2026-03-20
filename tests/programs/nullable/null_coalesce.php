<?php

declare(strict_types=1);

function first(?string $a, string $b): string
{
    return $a ?? $b;
}

echo first('foo', 'bar');
echo "\n";
echo first(null, 'bar');
echo "\n";
