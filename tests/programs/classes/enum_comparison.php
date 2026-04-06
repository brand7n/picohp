<?php

declare(strict_types=1);

enum Color: int
{
    case Red = 1;
    case Green = 2;
    case Blue = 3;
}

class Box
{
    public Color $color;

    public function __construct(Color $color)
    {
        $this->color = $color;
    }
}

$box = new Box(Color::Red);

if ($box->color === Color::Red) {
    echo "red\n";
} else {
    echo "not red\n";
}

if ($box->color === Color::Green) {
    echo "green\n";
} else {
    echo "not green\n";
}

echo "done\n";
