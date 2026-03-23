<?php

declare(strict_types=1);

enum Color: string
{
    case RED = 'red';
    case BLUE = 'blue';
    case GREEN = 'green';
}

function paint(string $surface, Color $color = Color::RED): string
{
    return $surface . " is " . $color->value;
}

echo paint("wall") . "\n";
echo paint("door", Color::BLUE) . "\n";
