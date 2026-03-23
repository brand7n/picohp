<?php

declare(strict_types=1);

function classify(int $x): string
{
    return match($x) {
        1, 2 => "low",
        3, 4 => "mid",
        default => "high",
    };
}

echo classify(1) . "\n";
echo classify(2) . "\n";
echo classify(3) . "\n";
echo classify(5) . "\n";
