<?php

declare(strict_types=1);

function formatFloat(float $f): string
{
    return (string) $f;
}

echo formatFloat(3.14);
echo "\n";
echo formatFloat(0.0);
echo "\n";
echo formatFloat(100.0);
echo "\n";
echo formatFloat(0.5);
echo "\n";
