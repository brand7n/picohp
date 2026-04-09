<?php

declare(strict_types=1);

function describe(string $color): string
{
    return match ($color) {
        'red' => 'warm',
        'blue' => 'cool',
        'green' => 'natural',
        default => 'unknown',
    };
}

echo describe('red') . "\n";
echo describe('blue') . "\n";
echo describe('green') . "\n";
echo describe('purple') . "\n";
