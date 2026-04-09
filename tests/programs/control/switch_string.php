<?php

declare(strict_types=1);

function describe(string $fruit): string
{
    switch ($fruit) {
        case 'apple':
            return 'red';
        case 'banana':
            return 'yellow';
        case 'grape':
            return 'purple';
        default:
            return 'unknown';
    }
}

echo describe('apple') . "\n";
echo describe('banana') . "\n";
echo describe('grape') . "\n";
echo describe('kiwi') . "\n";
